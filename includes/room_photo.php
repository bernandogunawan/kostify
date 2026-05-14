<?php
/**
 * Resolve a room listing photo path (web-relative, forward slashes).
 * Uses DB photo_path when the file exists; otherwise tries roompics/room {number}.jpg etc.
 */
function kostify_resolve_room_photo(string $room_number, ?string $photo_path_db): string
{
    $root = dirname(__DIR__);
    $db   = trim((string) $photo_path_db);
    if ($db !== '') {
        $full = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $db);
        if (is_file($full)) {
            return str_replace('\\', '/', $db);
        }
    }
    $num = trim(preg_replace('/\s+/', '', (string) $room_number));
    if ($num === '') {
        return '';
    }
    $candidates = [
        "roompics/room {$num}.jpg",
        "roompics/room{$num}.jpg",
        "roompics/{$num}.jpg",
        "roompics/room {$num}.jpeg",
        "roompics/room {$num}.png",
    ];
    foreach ($candidates as $rel) {
        $full = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        if (is_file($full)) {
            return str_replace('\\', '/', $rel);
        }
    }

    return '';
}
