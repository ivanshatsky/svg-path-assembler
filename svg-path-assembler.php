<?php

// ---------------------------------------------------------------------------
// SVG Path Assembler
//
// Reads an SVG file line by line, parses <path> and <polyline> elements,
// then joins path segments that share endpoints into continuous paths.
//
// Usage:  php svg_path_join.php input.svg
//
// Supported SVG path commands: M, L, H, V, A, Q, T, C, S, Z
// Both absolute (uppercase) and relative (lowercase) variants are supported
// during parsing. Output is in absolute or relative coordinates.
// ---------------------------------------------------------------------------


// ---------------------------------------------------------------------------
// CONSTANTS
// ---------------------------------------------------------------------------

// Flags for add_path(): control which ends are connected.
// These are combined with bitwise OR, e.g. TO_END | FROM_START.
//
//   TO_END   (0b00): append the new segment to the end   of the current path
//   TO_START (0b10): prepend the new segment at the start of the current path
//   FROM_START (0b00): take the new segment from its start point
//   FROM_END   (0b01): take the new segment from its end   point (reversed)
//
define('FROM_START', 0x00);
define('FROM_END',   0x01);
define('TO_END',     0x00);
define('TO_START',   0x02);

// Number of decimal places used when rounding output coordinates.
define('PRECISION', 5);


// ---------------------------------------------------------------------------
// REGEX BUILDING BLOCKS
//
// We build path-command regexes from small named pieces so the patterns stay
// readable and the pieces can be reused consistently.
// ---------------------------------------------------------------------------

// A single coordinate value: optional minus, digits, optional decimal part.
$rgx_pnt = '-?\d+(?:\.\d+)?';

// A radius value (always non-negative, used in arc commands).
$rgx_rad = '\d+(?:\.\d+)?';

// A separator between coordinate values: any mix of commas and whitespace.
$rgx_spc = '\s*[,\s]\s*';

// An (x,y) coordinate pair — no capture groups (used in non-capturing regexes).
$rgx_crd_nocap = "${rgx_pnt}${rgx_spc}${rgx_pnt}";

// An (x,y) coordinate pair — with capture groups (used when we need the values).
$rgx_crd_cap = "(${rgx_pnt})${rgx_spc}(${rgx_pnt})";


// ---------------------------------------------------------------------------
// PER-COMMAND REGEX PATTERNS
//
// Two parallel arrays:
//   $paths_nocap — patterns without capture groups, used inside the "envelope"
//                  regex that validates the overall path string structure.
//   $paths_cap   — patterns with capture groups, used when extracting individual
//                  command arguments from the matched command list.
//
// Each entry matches the command letter (both cases) plus its arguments.
// Commands that carry control points (Q, C, S, T) include those in the pattern.
// ---------------------------------------------------------------------------

$paths_nocap = [
    // LineTo: x y
    'L' => "[Ll]\\s*${rgx_crd_nocap}",

    // HorizontalLineTo: x  (y stays the same)
    'H' => "[Hh]\\s*${rgx_pnt}",

    // VerticalLineTo: y  (x stays the same)
    'V' => "[Vv]\\s*${rgx_pnt}",

    // Arc: rx ry x-rotation large-arc-flag sweep-flag x y
    'A' => "[Aa]\\s*${rgx_rad}${rgx_spc}${rgx_rad}\\s+${rgx_pnt}\\s+[01]${rgx_spc}[01]${rgx_spc}${rgx_crd_nocap}",

    // Quadratic Bézier: x1 y1  x y  (one control point)
    'Q' => "[Qq]\\s*${rgx_crd_nocap}${rgx_spc}${rgx_crd_nocap}",

    // Smooth Quadratic Bézier: x y  (control point is inferred)
    'T' => "[Tt]\\s*${rgx_crd_nocap}",

    // Cubic Bézier: x1 y1  x2 y2  x y  (two control points)
    'C' => "[Cc]\\s*${rgx_crd_nocap}${rgx_spc}${rgx_crd_nocap}${rgx_spc}${rgx_crd_nocap}",

    // Smooth Cubic Bézier: x2 y2  x y  (first control point is inferred)
    'S' => "[Ss]\\s*${rgx_crd_nocap}${rgx_spc}${rgx_crd_nocap}",
];

$paths_cap = [
    // L — groups: (x) (y)
    'L' => "[Ll]\\s*${rgx_crd_cap}",

    // H — group: (x)
    'H' => "[Hh]\\s*(${rgx_pnt})",

    // V — group: (y)
    'V' => "[Vv]\\s*(${rgx_pnt})",

    // A — groups: (rx) (ry) (rotation) (large-flag) (sweep-flag) (x) (y)
    'A' => "[Aa]\\s*(${rgx_rad})${rgx_spc}(${rgx_rad})\\s+(${rgx_pnt})\\s+([01])${rgx_spc}([01])${rgx_spc}${rgx_crd_cap}",

    // Q — groups: (x1) (y1) (x) (y)
    'Q' => "[Qq]\\s*${rgx_crd_cap}${rgx_spc}${rgx_crd_cap}",

    // T — groups: (x) (y)
    'T' => "[Tt]\\s*${rgx_crd_cap}",

    // C — groups: (x1) (y1) (x2) (y2) (x) (y)
    'C' => "[Cc]\\s*${rgx_crd_cap}${rgx_spc}${rgx_crd_cap}${rgx_spc}${rgx_crd_cap}",

    // S — groups: (x2) (y2) (x) (y)
    'S' => "[Ss]\\s*${rgx_crd_cap}${rgx_spc}${rgx_crd_cap}",
];


// ---------------------------------------------------------------------------
// CAPTURE GROUP INDEX MAP
//
// When $paths_cap patterns are joined with | into one big alternation regex,
// each branch occupies a different set of numbered capture groups.
//
// For each command we record:
//   'dest' => [gx, gy]     — group indices for the destination (end) point
//   'ctrl' => [[gx,gy],…]  — group indices for any control points (in order)
//
// Group numbering starts at 2 (group 0 is the whole match, group 1 is a match
// starting with the command letter skipping possible heading space(s)).
// We count through each pattern in $paths_cap in array order to assign them.
//
// HOW TO RECOUNT if you change $paths_cap:
//   Go through $paths_cap in definition order.  For each command, the groups
//   in that command's pattern are numbered sequentially after all previous
//   commands' groups.  Each (${rgx_pnt}) or (${rgx_rad}) in the pattern adds
//   one group.
//
//   L:  2 groups  → dest=[2,3]                            running total: 3
//   H:  1 group   → dest=[4, null]  (y from prev point)   running total: 4
//   V:  1 group   → dest=[null, 5]  (x from prev point)   running total: 5
//   A:  7 groups  → arc params=[6,7,8,9,10], dest=[11,12] running total: 12
//   Q:  4 groups  → ctrl=[[13,14]], dest=[15,16]          running total: 16
//   T:  2 groups  → dest=[17,18]                          running total: 18
//   C:  6 groups  → ctrl=[[19,20],[21,22]], dest=[23,24]  running total: 24
//   S:  4 groups  → ctrl=[[25,26]], dest=[27,28]          running total: 28
// ---------------------------------------------------------------------------

$cmd_group_map = [
    'L' => ['dest' => [2,  3],           'ctrl' => []],
    'H' => ['dest' => [4,  null],        'ctrl' => []],  // y carried from prev point
    'V' => ['dest' => [null, 5],         'ctrl' => []],  // x carried from prev point
    'A' => ['dest' => [11, 12],          'ctrl' => [],
            'arc'  => [6, 7, 8, 9, 10]], // rx,ry,rotation,large-flag,sweep-flag
    'Q' => ['dest' => [15, 16],          'ctrl' => [[13, 14]]],
    'T' => ['dest' => [17, 18],          'ctrl' => []],
    'C' => ['dest' => [23, 24],          'ctrl' => [[19, 20], [21, 22]]],
    'S' => ['dest' => [27, 28],          'ctrl' => [[25, 26]]],
];


// ---------------------------------------------------------------------------
// ASSEMBLED REGEXES
//
// $rgx_paths_common — validates an entire "d" attribute and captures:
//     group 1,2 : starting M x,y
//     group 3   : the remainder of the command string (fed to $rgx_paths_commands)
//     group 4   : optional closing Z/z
//
// $rgx_paths_commands — extracts individual commands from group 3 above.
//     group 1 : the full matched command token (letter + arguments)
//     groups 2…N : the per-command capture groups described in $cmd_group_map
// ---------------------------------------------------------------------------

$rgx_nocap = implode('|', $paths_nocap);
$rgx_cap   = implode('|', $paths_cap);

$rgx_paths_common   = "/^\\s*[Mm]\\s*${rgx_crd_cap}((?:\\s*(?:${rgx_nocap}))+)(?:\\s*([zZ]))?\\s*$/";
$rgx_paths_commands = "/\\s*(${rgx_cap})/";


// ---------------------------------------------------------------------------
// KNOWN SVG TAGS (used by parse_tag())
// ---------------------------------------------------------------------------

$tags = [
    '\?xml', 'svg', 'defs', 'clipPath',
    'g', 'line', 'polyline', 'path', 'rect', 'circle',
];
$tag_pattern = '#<(' . implode('|', $tags) . ')\s*(\s+[^>]*[^/])?(/)?' . '>#';


// ---------------------------------------------------------------------------
// CLASS: PathPoint
//
// A node in the doubly-linked list that represents one point on the path.
// Each point knows its coordinates and has:
//   ->next  — a PathRoute leading to the next point (forward direction)
//   ->prev  — a PathRoute leading to the previous point (backward direction)
// ---------------------------------------------------------------------------

class PathPoint {
    public float  $x, $y;
    public ?PathRoute $next = null;  // route to the next point (forward)
    public ?PathRoute $prev = null;  // route to the previous point (backward)

    public function __construct(float $x, float $y) {
        $this->x = $x;
        $this->y = $y;
    }

    // Returns "x,y" string (absolute coordinates).
    public function get_point(): string {
        return round($this->x, PRECISION) . ',' . round($this->y, PRECISION);
    }

    // Returns "x,y" string relative to an origin ($ox, $oy).
    public function get_relative_point(float $ox = 0, float $oy = 0): string {
        return round($this->x - $ox, PRECISION) . ',' . round($this->y - $oy, PRECISION);
    }
}


// ---------------------------------------------------------------------------
// CLASS: PathRoute
//
// Represents a directed edge between two PathPoints — i.e. one SVG drawing
// command.  The same geometric segment is represented by TWO PathRoute objects:
//   - one stored in point->next (forward direction)
//   - one stored in point->prev (backward direction)
//
// Fields:
//   $point  — the PathPoint this route leads TO
//   $cmd    — uppercase SVG command letter ('L', 'A', 'Q', 'C', …)
//   $args   — associative array of extra arguments beyond the destination point.
//             For most commands this is empty.  See parse_path() for details.
// ---------------------------------------------------------------------------

class PathRoute {
    public ?PathPoint $point = null;
    public string     $cmd   = 'L';
    public array      $args  = [];  // extra arguments; structure varies by command
}


// ---------------------------------------------------------------------------
// CLASS: SVGPath
//
// A doubly-linked list of PathPoints, representing a complete or partial path.
//
// $start — first PathPoint
// $end   — last PathPoint  (same as $start when the path is closed)
// $pieces — number of segments (edges) in the path
// $closed — TRUE if the path forms a closed loop
// ---------------------------------------------------------------------------

class SVGPath {
    public ?PathPoint $start  = null;
    public ?PathPoint $end    = null;
    public int        $pieces = 0;
    public bool       $closed = false;

    // --- Endpoint checks ---

    public function is_start_point(float $x, float $y): bool {
        return $this->start instanceof PathPoint
            && $this->start->x === $x
            && $this->start->y === $y;
    }

    public function is_end_point(float $x, float $y): bool {
        return $this->end instanceof PathPoint
            && $this->end->x === $x
            && $this->end->y === $y;
    }

    // -----------------------------------------------------------------------
    // get_path()
    //
    // Serialises the path back to an SVG "d" attribute string.
    //
    // Parameters:
    //   $relative — if TRUE, emit lowercase (relative) commands; coordinates
    //               are expressed relative to the current pen position.
    //   $reverse  — if TRUE, traverse the list backwards (end → start).
    //   $shift    — for closed paths: rotate the start point by this many
    //               segments (useful when you want a different "seam" point).
    // -----------------------------------------------------------------------
    public function get_path(bool $relative = false, bool $reverse = false, int $shift = 0): ?string {
        if (!$this->pieces) return null;

        $points        = $this->pieces;
        $current_point = $reverse ? $this->end : $this->start;

        // Shift only makes sense on closed paths.
        $shift = $this->closed ? ($shift % $this->pieces) : 0;
        for ($s = 0; $s < $shift; $s++) {
            $current_point = $reverse
                ? $current_point->prev->point
                : $current_point->next->point;
        }

        // Emit the MoveTo command for the starting point.
        $path      = 'M' . $current_point->get_point();
        $current_x = $current_point->x;
        $current_y = $current_point->y;

        while ($points > 0) {
            // Follow the linked list in the chosen direction.
            $route = $reverse ? $current_point->prev : $current_point->next;

            // For shifted closed paths we wrap around at the end of the list.
            if ($shift && $route === null) {
                $route = $reverse ? $this->end->prev : $this->start->next;
            }

            $cmd        = $route->cmd;
            $args       = $route->args;
            $next_point = $route->point;

            // The last segment of a closed path can be expressed as Z.
            if ($this->closed && $points === 1 && $cmd === 'L') {
                $path .= $relative ? ' z' : ' Z';
            } else {
                $out_cmd = $relative ? strtolower($cmd) : $cmd;

                switch ($cmd) {

                    // --- LineTo: no extra arguments ---
                    case 'L':
                        $path .= ' ' . $out_cmd
                              . ($relative
                                    ? $next_point->get_relative_point($current_x, $current_y)
                                    : $next_point->get_point());
                        break;

                    // --- HorizontalLineTo ---
                    case 'H':
                        if ($relative) {
                            $path .= ' h' . round($next_point->x - $current_x, PRECISION);
                        } else {
                            $path .= ' H' . round($next_point->x, PRECISION);
                        }
                        break;

                    // --- VerticalLineTo ---
                    case 'V':
                        if ($relative) {
                            $path .= ' v' . round($next_point->y - $current_y, PRECISION);
                        } else {
                            $path .= ' V' . round($next_point->y, PRECISION);
                        }
                        break;

                    // --- Arc ---
                    // $args keys: rx, ry, rotation, large_flag, sweep_flag
                    // When reversing a path the sweep direction must be flipped.
                    case 'A':
                        $path .= ' ' . $out_cmd
                              . $args['rx']         . ','
                              . $args['ry']         . ' '
                              . $args['rotation']   . ' '
                              . $args['large_flag'] . ','
                              . $args['sweep_flag'] . ' '
                              . ($relative
                                    ? $next_point->get_relative_point($current_x, $current_y)
                                    : $next_point->get_point());
                        break;

                    // --- Quadratic Bézier ---
                    // $args['ctrl'] = [[x1,y1]]  (one control point)
                    // Control points are stored in absolute coordinates and
                    // converted to relative here if needed.
                    case 'Q':
                        [$cx1, $cy1] = $args['ctrl'][0];
                        if ($relative) {
                            $path .= ' q'
                                  . round($cx1 - $current_x, PRECISION) . ','
                                  . round($cy1 - $current_y, PRECISION) . ' '
                                  . $next_point->get_relative_point($current_x, $current_y);
                        } else {
                            $path .= ' Q'
                                  . round($cx1, PRECISION) . ','
                                  . round($cy1, PRECISION) . ' '
                                  . $next_point->get_point();
                        }
                        break;

                    // --- Smooth Quadratic Bézier ---
                    // No explicit control point — it is reflected from the
                    // previous Q/T command by the SVG renderer.
                    case 'T':
                        $path .= ' ' . $out_cmd
                              . ($relative
                                    ? $next_point->get_relative_point($current_x, $current_y)
                                    : $next_point->get_point());
                        break;

                    // --- Cubic Bézier ---
                    // $args['ctrl'] = [[x1,y1],[x2,y2]]  (two control points)
                    case 'C':
                        [$cx1, $cy1] = $args['ctrl'][0];
                        [$cx2, $cy2] = $args['ctrl'][1];
                        if ($relative) {
                            $path .= ' c'
                                  . round($cx1 - $current_x, PRECISION) . ','
                                  . round($cy1 - $current_y, PRECISION) . ' '
                                  . round($cx2 - $current_x, PRECISION) . ','
                                  . round($cy2 - $current_y, PRECISION) . ' '
                                  . $next_point->get_relative_point($current_x, $current_y);
                        } else {
                            $path .= ' C'
                                  . round($cx1, PRECISION) . ','
                                  . round($cy1, PRECISION) . ' '
                                  . round($cx2, PRECISION) . ','
                                  . round($cy2, PRECISION) . ' '
                                  . $next_point->get_point();
                        }
                        break;

                    // --- Smooth Cubic Bézier ---
                    // $args['ctrl'] = [[x2,y2]]  (only the second control point;
                    // the first is inferred from the previous C/S command)
                    case 'S':
                        [$cx2, $cy2] = $args['ctrl'][0];
                        if ($relative) {
                            $path .= ' s'
                                  . round($cx2 - $current_x, PRECISION) . ','
                                  . round($cy2 - $current_y, PRECISION) . ' '
                                  . $next_point->get_relative_point($current_x, $current_y);
                        } else {
                            $path .= ' S'
                                  . round($cx2, PRECISION) . ','
                                  . round($cy2, PRECISION) . ' '
                                  . $next_point->get_point();
                        }
                        break;

                    default:
                        echo "ERROR: get_path: unsupported command '$cmd'\n";
                        return null;
                }

                $current_point = $next_point;
                $current_x     = $current_point->x;
                $current_y     = $current_point->y;
            }

            $points--;
        }

        return $path;
    }

    // -----------------------------------------------------------------------
    // reverse_path()
    //
    // Reverses the direction of the entire path in-place by swapping every
    // point's ->next and ->prev pointers, then swapping $start and $end.
    //
    // Note: for arc commands the sweep_flag must be toggled so the arc still
    // curves in the visually correct direction when traversed backwards.
    // -----------------------------------------------------------------------
    public function reverse_path(): void {
        if (!$this->pieces) return;

        $next_point = $this->start;
        do {
            $current_point = $next_point;
            $next_point    = $current_point->next ? $current_point->next->point : null;

            // Swap the forward and backward routes on this point.
            [$current_point->next, $current_point->prev] =
                [$current_point->prev, $current_point->next];

            // When an arc route is reversed its sweep direction must flip
            // (otherwise the arc bows the wrong way).
            foreach ([$current_point->next, $current_point->prev] as $route) {
                if ($route && $route->cmd === 'A') {
                    $route->args['sweep_flag'] = 1 - $route->args['sweep_flag'];
                }
            }
        } while ($next_point);

        // After reversing all pointers, what was the end becomes the start.
        [$this->start, $this->end] = [$this->end, $this->start];
    }

    // -----------------------------------------------------------------------
    // add_path()
    //
    // Appends or prepends $added_path to $this, connecting at the shared
    // endpoint.  The $flags bitmask controls how the two paths are oriented:
    //
    //   TO_END   | FROM_START — append $added_path (forward) at the end
    //   TO_END   | FROM_END   — append $added_path (reversed) at the end
    //   TO_START | FROM_END   — prepend $added_path (forward) at the start
    //   TO_START | FROM_START — prepend $added_path (reversed) at the start
    //
    // The method assumes the caller has already verified that the junction
    // point is indeed shared; it will print a warning otherwise.
    // -----------------------------------------------------------------------
    public function add_path(SVGPath $added_path, int $flags): void {
        if (!$this->pieces || !$added_path->pieces) return;

        $reverse_added = (bool)($flags & FROM_END);
        $reverse_self  = (bool)($flags & TO_START);

        // Choose which end of each path will be at the junction.
        $added_point = $reverse_added ? $added_path->end   : $added_path->start;
        $self_point  = $reverse_self  ? $this->start        : $this->end;

        // Sanity-check: the two points must be at the same position.
        if ($self_point->get_point() !== $added_point->get_point()) {
            echo "INCONSISTENCY in add_path(): flags=$flags"
               . ", self_point=" . $self_point->get_point()
               . ", added_point=" . $added_point->get_point() . "\n";
            return;
        }

        // If the added path needs to be reversed for orientation, do it now.
        // We XOR because reversing both is the same as reversing neither.
        if ($reverse_added xor $reverse_self) {
            $added_path->reverse_path();
        }

        if ($reverse_self) {
            // Prepend: attach the end of $added_path to our current start.
            // (After possible reversal, the added path's end is the junction.)
            $this->start->prev                     = $added_path->end->prev;
            $added_path->end->prev->point->next->point = $this->start;
            $this->start                           = $added_path->start;
        } else {
            // Append: attach the start of $added_path to our current end.
            $this->end->next                       = $added_path->start->next;
            $added_path->start->next->point->prev->point = $this->end;
            $this->end                             = $added_path->end;
        }

        $this->pieces += $added_path->pieces;

        // If start and end are now the same point, the path has closed.
        if ($this->start->get_point() === $this->end->get_point()) {
            $this->closed = true;
        }
    }
}


// ---------------------------------------------------------------------------
// parse_tag()
//
// Tries to parse one SVG opening tag from $line.
// Returns an associative array on success:
//   ['name'   => tag name string,
//    'atts'   => ['attr' => 'value', …],
//    'closed' => bool  (true if self-closing "/>" tag)]
// Returns NULL if the line does not contain a recognised tag.
// ---------------------------------------------------------------------------
function parse_tag(string $line): ?array {
    global $tag_pattern;

    if (!preg_match($tag_pattern, $line, $tag_matches)) return null;

    $atts = [];
    if (isset($tag_matches[2])) {
        preg_match_all('#\s+([a-zA-Z][-\w]*)="([^"]*)"#', $tag_matches[2], $att_matches);
        for ($m = 0; $m < count($att_matches[0]); $m++) {
            $atts[$att_matches[1][$m]] = $att_matches[2][$m];
        }
    }

    return [
        'name'   => $tag_matches[1],
        'atts'   => $atts,
        'closed' => isset($tag_matches[3]),
    ];
}


// ---------------------------------------------------------------------------
// parse_path()
//
// Converts an SVG path "d" string into an SVGPath linked-list object.
//
// The parsing is done in two regex passes:
//
//   Pass 1 ($rgx_paths_common):
//     Validates the overall structure and extracts:
//       — the starting M x,y  (groups 1,2)
//       — the remaining command string (group 3)
//       — an optional trailing Z (group 4)
//
//   Pass 2 ($rgx_paths_commands, applied to group 3):
//     Extracts each individual command together with its arguments.
//     The capture groups in each match are indexed according to
//     $cmd_group_map (see above).
//
// Control points for Q, C, S are stored in absolute coordinates inside
// PathRoute::$args['ctrl'] as [[x1,y1], …] so that get_path() can apply
// relative offsets or scaling without re-parsing.
// ---------------------------------------------------------------------------
function parse_path(string $path): ?SVGPath {
    global $rgx_paths_common, $rgx_paths_commands, $cmd_group_map;

    $parsed_path = new SVGPath;

    // Pass 1: validate structure and extract the starting M coordinate.
    if (!preg_match($rgx_paths_common, $path, $path_parts)) {
        echo "ERROR: parse_path: cannot parse overall path structure: $path\n";
        return null;
    }

    $start_x     = (float)$path_parts[1];
    $start_y     = (float)$path_parts[2];
    $start_point = new PathPoint($start_x, $start_y);

    $parsed_path->start = $start_point;
    $prev_point         = $start_point;
    $prev_x             = $start_x;
    $prev_y             = $start_y;

    // Pass 2: iterate over every drawing command that follows the M.
    if (!preg_match_all($rgx_paths_commands, $path_parts[3], $path_commands)) {
        echo "ERROR: parse_path: cannot parse path commands: $path\n";
        return null;
    }

    $num_commands = count($path_commands[0]);

    for ($i = 0; $i < $num_commands; $i++) {

        // The command letter is the first character of the full match.
        $raw_cmd       = $path_commands[1][$i][0];  // e.g. 'c', 'L', 'A'
        $upcase_cmd    = strtoupper($raw_cmd);
        $is_relative   = ctype_lower($raw_cmd);      // lowercase → relative coords

        // Look up this command in the group map.
        if (!isset($cmd_group_map[$upcase_cmd])) {
            echo "ERROR: parse_path: unsupported command '$raw_cmd'\n";
            return null;
        }
        $map = $cmd_group_map[$upcase_cmd];

        // --- Extract the destination (end) point ---

        [$gx, $gy] = $map['dest'];

        // H only moves horizontally: keep the previous Y.
        $dest_x = ($gx !== null)
            ? (float)$path_commands[$gx][$i]
            : $prev_x;

        // V only moves vertically: keep the previous X.
        $dest_y = ($gy !== null)
            ? (float)$path_commands[$gy][$i]
            : $prev_y;

        // Convert relative to absolute if necessary.
        if ($is_relative) {
            $dest_x += $prev_x;
            $dest_y += $prev_y;
        }

        // --- Extract control points (Q, C, S) ---

        $ctrl_pts = [];
        foreach ($map['ctrl'] as [$gcx, $gcy]) {
            $cx = (float)$path_commands[$gcx][$i];
            $cy = (float)$path_commands[$gcy][$i];
            if ($is_relative) {
                $cx += $prev_x;
                $cy += $prev_y;
            }
            $ctrl_pts[] = [$cx, $cy];
        }

        // --- Extract arc parameters (A only) ---

        $arc_args = [];
        if ($upcase_cmd === 'A' && isset($map['arc'])) {
            [$g_rx, $g_ry, $g_rot, $g_lf, $g_sf] = $map['arc'];
            $arc_args = [
                'rx'         => (float)$path_commands[$g_rx][$i],
                'ry'         => (float)$path_commands[$g_ry][$i],
                'rotation'   => (float)$path_commands[$g_rot][$i],
                'large_flag' => (int)$path_commands[$g_lf][$i],
                'sweep_flag' => (int)$path_commands[$g_sf][$i],
            ];
        }

        // --- Detect implicit path closure ---
        //
        // If this is the last command and its destination coincides with the
        // start point (and there is no explicit Z), treat the path as closed.
        $is_last = ($i === $num_commands - 1);
        if ($is_last && $parsed_path->is_start_point($dest_x, $dest_y) && !isset($path_parts[4])) {
            $current_point  = $parsed_path->start;
            $parsed_path->closed = true;
        } else {
            $current_point = new PathPoint($dest_x, $dest_y);
        }

        $parsed_path->end = $current_point;

        // --- Build the forward route (prev_point → current_point) ---

        $route_forward        = new PathRoute;
        $route_forward->point = $current_point;
        $route_forward->cmd   = $upcase_cmd;
        $route_forward->args  = array_merge($arc_args, $ctrl_pts ? ['ctrl' => $ctrl_pts] : []);
        $prev_point->next     = $route_forward;

        // --- Build the backward route (current_point → prev_point) ---
        // For most commands the backward args are identical to the forward args.
        // Exception: arc sweep_flag must be toggled when going backwards.

        $back_args = $route_forward->args;
        if ($upcase_cmd === 'A') {
            $back_args['sweep_flag'] = 1 - $back_args['sweep_flag'];
        }
        // For C/S reversed, the control point order must be swapped so the
        // curve mirrors correctly (the last ctrl point becomes the first).
        if (($upcase_cmd === 'C' || $upcase_cmd === 'S') && !empty($back_args['ctrl'])) {
            $back_args['ctrl'] = array_reverse($back_args['ctrl']);
        }

        $route_back        = new PathRoute;
        $route_back->point = $prev_point;
        $route_back->cmd   = $upcase_cmd;
        $route_back->args  = $back_args;
        $current_point->prev = $route_back;

        $parsed_path->pieces++;

        // Advance the "pen" position for the next iteration.
        $prev_point = $current_point;
        $prev_x     = $dest_x;
        $prev_y     = $dest_y;
    }

    // --- Handle explicit Z (close path) ---
    //
    // When a Z command is present we add one more L segment from the last
    // point back to the start, completing the loop in the linked list.
    if (isset($path_parts[4])) {
        $parsed_path->pieces++;
        $parsed_path->closed = true;
        $last_point          = $parsed_path->end;

        $route_forward        = new PathRoute;
        $route_forward->point = $parsed_path->start;
        $route_forward->cmd   = 'L';
        $route_forward->args  = [];
        $last_point->next     = $route_forward;

        $route_back        = new PathRoute;
        $route_back->point = $last_point;
        $route_back->cmd   = 'L';
        $route_back->args  = [];
        $parsed_path->start->prev = $route_back;

        // In a closed path $end and $start are the same node.
        $parsed_path->end = $parsed_path->start;

    } elseif ($parsed_path->start->get_point() === $parsed_path->end->get_point()) {
        // Start and end coordinates match without an explicit Z.
        $parsed_path->closed = true;
    }

    return $parsed_path;
}


// ---------------------------------------------------------------------------
// MAIN — read input file and assemble paths
// ---------------------------------------------------------------------------

if ($argc < 2) {
    echo "Usage: php svg_path_join.php <input.svg>\n";
    exit(1);
}

// All parsed paths collected here.  Open (incomplete) paths stay here until
// a matching endpoint is found; closed paths are kept for final output.
$known_paths = [];

$infile = fopen($argv[1], 'r') or die("Unable to open input file: {$argv[1]}\n");

while ($line = fgets($infile)) {

    if (strpos($line, '</') !== false) {
        // Closing tags are not needed for path assembly.
        continue;
    }

    $object = parse_tag($line);
    if (!$object) {
        echo "WARNING: unparsed line: $line";
        continue;
    }

    // Convert supported element types to a path "d" string.
    $new_path_txt = null;

    switch ($object['name']) {

        case 'polyline':
            // A polyline is a series of x,y pairs — convert to M + L commands.
            if (!isset($object['atts']['points'])) {
                echo "ERROR: No points defined for polyline: $line";
                break;
            }
            global $rgx_crd_cap;
            if (!preg_match_all("/(?<![^\\s])${rgx_crd_cap}(?:\\s|$)/", $object['atts']['points'], $pts)) {
                echo "ERROR: Cannot parse polyline points: $line";
                break;
            }
            if (count($pts[0]) < 2) {
                echo "ERROR: Not enough points for polyline: $line";
                break;
            }
            $new_path_txt = "M{$pts[0][0]}";
            for ($p = 1; $p < count($pts[0]); $p++) {
                // Detect implicit closure (last point == first point).
                if ($p === count($pts[0]) - 1 && $pts[0][$p] === $pts[0][0]) {
                    $new_path_txt .= ' Z';
                } else {
                    $new_path_txt .= " L{$pts[0][$p]}";
                }
            }
            break;

        case 'path':
            if (!isset($object['atts']['d'])) {
                echo "ERROR: No path commands defined for path: $line";
                break;
            }
            $new_path_txt = $object['atts']['d'];
            break;

        default:
            // Silently skip structural/decorative tags (svg, g, defs, rect, …).
            break;
    }

    if (!$new_path_txt) continue;

    $new_path = parse_path($new_path_txt);
    if (!$new_path) {
        echo "ERROR: Cannot parse path '$new_path_txt' from: $line";
        continue;
    }

    // -------------------------------------------------------------------
    // JOIN OPERATION
    //
    // Try to attach the new path to an existing open path by matching
    // their endpoints.  Four orientations are possible:
    //
    //   known_end   == new_start  → append new  (forward)  to known end
    //   known_end   == new_end    → append new  (reversed) to known end
    //   known_start == new_end    → prepend new (forward)  to known start
    //   known_start == new_start  → prepend new (reversed) to known start
    //
    // After merging, we do a second pass over the array to see whether the
    // updated path's new free endpoint now matches any other stored path.
    // -------------------------------------------------------------------

    if ($new_path->closed) {
        // Closed paths cannot be extended; just store them.
        $known_paths[] = $new_path;
        continue;
    }

    $merged_index = null;
    $last_free    = null;   // the free endpoint after merging (for second pass)

    foreach ($known_paths as $idx => $known_path) {
        if ($known_path->closed) continue;

        $k_start = $known_path->start->get_point();
        $k_end   = $known_path->end->get_point();
        $n_start = $new_path->start->get_point();
        $n_end   = $new_path->end->get_point();

        if ($k_end === $n_start) {
            $known_path->add_path($new_path, TO_END | FROM_START);
            $last_free    = $new_path->end->get_point();
            $merged_index = $idx;
            break;
        } elseif ($k_end === $n_end) {
            $known_path->add_path($new_path, TO_END | FROM_END);
            $last_free    = $new_path->start->get_point();
            $merged_index = $idx;
            break;
        } elseif ($k_start === $n_end) {
            $known_path->add_path($new_path, TO_START | FROM_END);
            $last_free    = $new_path->start->get_point();
            $merged_index = $idx;
            break;
        } elseif ($k_start === $n_start) {
            $known_path->add_path($new_path, TO_START | FROM_START);
            $last_free    = $new_path->end->get_point();
            $merged_index = $idx;
            break;
        }
    }

    if ($merged_index === null) {
        // No existing path shares an endpoint; park this path for later.
        $known_paths[] = $new_path;
        continue;
    }

    if ($known_paths[$merged_index]->closed) continue;

    // Second pass: the merged path may now connect to another parked path.
    for ($j = $merged_index + 1; $j < count($known_paths); $j++) {
        $candidate = $known_paths[$j];
        if ($candidate->closed) continue;

        if ($candidate->start->get_point() === $last_free) {
            $known_paths[$merged_index]->add_path($candidate, FROM_START | TO_END);
            array_splice($known_paths, $j, 1);
            break;
        } elseif ($candidate->end->get_point() === $last_free) {
            $known_paths[$merged_index]->add_path($candidate, FROM_END | TO_END);
            array_splice($known_paths, $j, 1);
            break;
        }
    }
}

fclose($infile);


// ---------------------------------------------------------------------------
// OUTPUT — emit one <path> element per assembled path.
// Change get_path(TRUE) to get_path(FALSE) for absolute coordinates.
// ---------------------------------------------------------------------------

foreach ($known_paths as $path) {
    $d = $path->get_path(false);   // absolute coordinates, change to "true" for relative
    if ($d !== null) {
        echo '<path d="', $d, '"/>', PHP_EOL;
    }
}
