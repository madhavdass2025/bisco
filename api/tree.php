<?php
// api/tree.php

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';

if (!Auth::check()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$db = Database::getConnection();
$user = Auth::user();

// Rank names reference
$rankNames = [
    0 => 'Member',
    1 => 'Promoter',
    2 => 'Senior Promoter',
    3 => 'Team Leader',
    4 => 'Team Manager',
    5 => 'Area Manager',
    6 => 'Zonal Manager',
    7 => 'Regional Manager',
    8 => 'State Head',
    9 => 'National Head',
    10 => 'Global Head',
    11 => 'Ambassador',
    12 => 'Crown Ambassador'
];

function buildNetworkTree(PDO $db, int $parentId, array $rankNames, int $currentLevel = 1, int $maxLevel = 12): array {
    if ($currentLevel > $maxLevel) {
        return [];
    }

    $stmt = $db->prepare("
        SELECT u.id, u.full_name, u.phone, u.rank_level, u.created_at,
               (SELECT COUNT(*) FROM subscriptions s WHERE s.user_id = u.id AND s.status = 'active') as active_subs
        FROM users u
        WHERE u.sponsor_id = ?
        ORDER BY u.id ASC
    ");
    $stmt->execute([$parentId]);
    $children = $stmt->fetchAll();

    $treeNodes = [];
    foreach ($children as $child) {
        $childId = (int)$child['id'];
        $rankLevel = (int)$child['rank_level'];

        $subTree = buildNetworkTree($db, $childId, $rankNames, $currentLevel + 1, $maxLevel);

        $treeNodes[] = [
            'id' => $childId,
            'full_name' => $child['full_name'],
            'phone' => substr($child['phone'], 0, 3) . '****' . substr($child['phone'], -3),
            'rank_level' => $rankLevel,
            'rank_name' => $rankNames[$rankLevel] ?? 'Member',
            'active_subs' => (int)$child['active_subs'],
            'level' => $currentLevel,
            'children_count' => count($subTree),
            'children' => $subTree
        ];
    }

    return $treeNodes;
}

$tree = buildNetworkTree($db, $user['id'], $rankNames, 1, 12);

echo json_encode([
    'success' => true,
    'user' => [
        'id' => $user['id'],
        'full_name' => $user['full_name'],
        'rank_level' => $user['rank_level'],
        'rank_name' => $rankNames[$user['rank_level']] ?? 'Member'
    ],
    'tree' => $tree
]);
