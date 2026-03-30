<?php

header('Content-Type: application/json; charset=utf-8');

function parseInterfaceFilter(?string $raw): array
{
    if ($raw === null) {
        return [];
    }

    $raw = trim($raw);
    if ($raw === '' || strtolower($raw) === 'all' || $raw === '*') {
        return [];
    }

    $items = preg_split('/[\,\s]+/', $raw) ?: [];
    $items = array_map('trim', $items);
    $items = array_filter($items, static fn ($value) => $value !== '');

    return array_values(array_unique($items));
}

function filterInterfaces(array $interfaces, array $wanted): array
{
    if ($wanted === []) {
        return $interfaces;
    }

    $ordered = [];
    foreach ($interfaces as $iface) {
        $name = isset($iface['name']) ? (string) $iface['name'] : '';
        $alias = isset($iface['alias']) ? (string) $iface['alias'] : '';

        $matchIndex = null;
        foreach ([$name, $alias] as $candidate) {
            if ($candidate === '') {
                continue;
            }

            $index = array_search($candidate, $wanted, true);
            if ($index !== false && ($matchIndex === null || $index < $matchIndex)) {
                $matchIndex = $index;
            }
        }

        if ($matchIndex !== null) {
            $ordered[] = [
                'index' => $matchIndex,
                'iface' => $iface,
            ];
        }
    }

    usort($ordered, static fn ($a, $b) => $a['index'] <=> $b['index']);

    return array_map(static fn ($item) => $item['iface'], $ordered);
}

function lastArrayEntry(array $items): ?array
{
    if ($items === []) {
        return null;
    }

    $values = array_values($items);
    $last = $values[count($values) - 1] ?? null;

    return is_array($last) ? $last : null;
}

function parseMonthlyEstimate(string $output): ?array
{
    if ($output === '') {
        return null;
    }

    if (!preg_match('/^\s*estimated\s+(.+?)\s+\|\s+(.+?)\s+\|\s+(.+?)(?:\s*\|.*)?$/mi', $output, $matches)) {
        return null;
    }

    return [
        'rx' => trim($matches[1]),
        'tx' => trim($matches[2]),
        'total' => trim($matches[3]),
    ];
}

$filter = parseInterfaceFilter($_GET['interfaces'] ?? null);

$output = shell_exec('vnstat --json');
if (!is_string($output) || $output === '') {
    http_response_code(500);
    echo json_encode([
        'error' => 'Impossible de recuperer les données vnStat.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode($output, true);
if (!is_array($data)) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Reponse vnStat invalide.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($filter !== []) {
    $data['interfaces'] = filterInterfaces($data['interfaces'] ?? [], $filter);
}

if (isset($data['interfaces']) && is_array($data['interfaces'])) {
    $data['interfaces'] = array_map(static function (array $iface): array {
        $traffic = isset($iface['traffic']) && is_array($iface['traffic']) ? $iface['traffic'] : [];
        $day = lastArrayEntry(isset($traffic['day']) && is_array($traffic['day']) ? $traffic['day'] : []);
        $month = lastArrayEntry(isset($traffic['month']) && is_array($traffic['month']) ? $traffic['month'] : []);

        $estimated = null;
        $name = isset($iface['name']) ? (string) $iface['name'] : '';
        if ($name !== '') {
            $monthlyOutput = shell_exec(sprintf('vnstat -m -i %s', escapeshellarg($name)));
            if (is_string($monthlyOutput)) {
                $estimated = parseMonthlyEstimate($monthlyOutput);
            }
        }

        $iface['summary'] = [
            'day' => $day,
            'month' => $month,
            'estimated' => $estimated,
        ];

        return $iface;
    }, $data['interfaces']);
}

echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
