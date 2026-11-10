<?php
if (!function_exists('getUserType')) {
    // Adjust if you already have this function elsewhere; keep only ONE definition.
    function getUserType(string $role): string
    {
        return match ($role) {
            'student' => 'student',
            'hte_adviser', 'internship_adviser' => 'adviser',
            'superadmin', 'internship_admin' => 'admin',
            default => '',
        };
    }
}

if (!function_exists('canManageRoomMembers')) {
    function canManageRoomMembers(array $room): bool
    {
        $role = $_SESSION['role'] ?? '';
        $uid = (int) ($_SESSION['user_id'] ?? 0);

        // Department rooms (IT / CE / EE): superadmin + internship_admin only
        if (($room['room_type'] ?? '') === 'department') {
            return in_array($role, ['superadmin', 'internship_admin'], true);
        }

        // Regular rooms: only the adviser who owns the room
        return getUserType($role) === 'adviser'
            && !empty($room['adviser_id'])
            && (int) $room['adviser_id'] === $uid;
    }
}