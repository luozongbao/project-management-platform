<?php
require_once __DIR__ . '/../includes/functions.php';

$tests = [
    'weighted completion' => [
        [
            ['completion_percentage' => 0, 'estimated_hours' => 1],
            ['completion_percentage' => 100, 'estimated_hours' => 3],
        ],
        75.0,
    ],
    'missing estimate uses equal average' => [
        [
            ['completion_percentage' => 0, 'estimated_hours' => 1],
            ['completion_percentage' => 100, 'estimated_hours' => null],
        ],
        50.0,
    ],
    'zero estimate uses equal average' => [
        [
            ['completion_percentage' => 0, 'estimated_hours' => 0],
            ['completion_percentage' => 100, 'estimated_hours' => 3],
        ],
        50.0,
    ],
    'empty group is zero' => [[], 0.0],
    'manday and eight hours have equal weight' => [
        [
            ['completion_percentage' => 0, 'estimated_hours' => 8],
            ['completion_percentage' => 100, 'estimated_hours' => 8],
        ],
        50.0,
    ],
];

foreach ($tests as $name => [$tasks, $expected]) {
    $actual = calculateTaskGroupCompletion($tasks);
    if (abs($actual - $expected) > 0.0001) {
        fwrite(STDERR, "$name: expected $expected, got $actual\n");
        exit(1);
    }
}

$nested = calculateTaskCompletionMap([
    ['id' => 1, 'parent_task_id' => null, 'completion_percentage' => 0, 'estimated_hours' => 3],
    ['id' => 2, 'parent_task_id' => 1, 'completion_percentage' => 0, 'estimated_hours' => 1],
    ['id' => 3, 'parent_task_id' => 1, 'completion_percentage' => 100, 'estimated_hours' => 3],
    ['id' => 4, 'parent_task_id' => null, 'completion_percentage' => 100, 'estimated_hours' => 1],
]);
if (abs($nested['task_completion'][1] - 75.0) > 0.0001
    || abs($nested['project_completion'] - 81.25) > 0.0001) {
    fwrite(STDERR, "nested parent/project aggregation failed\n");
    exit(1);
}

$legacyProject = calculateTaskCompletionMap([]);
if ($legacyProject['project_completion'] !== 0.0
    || $legacyProject['task_completion'] !== []) {
    fwrite(STDERR, "empty project completion failed\n");
    exit(1);
}

echo "Task completion calculations passed (" . count($tests) . " group cases plus nested cases).\n";
