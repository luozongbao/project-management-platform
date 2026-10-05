# Calculate Effort-Weighted Completion

**Status:** Open  
**Type:** Feature / Progress Calculation  
**Depends on:** [issue-006](./issue-006.md)  
**Implementation order:** 2 of 2

## Problem

Task, parent-task, and project completion currently use equal averages. A
small task therefore affects reported completion as much as a task that
represents substantially more work. Use task time estimates as weights while
preserving sensible progress for existing tasks that have no estimates.

## Requirements

- For any group of sibling tasks where every task has a positive time
  estimate, calculate completion as the effort-weighted average:
  `sum(estimated_hours * completion_percentage) / sum(estimated_hours)`.
- Apply this calculation to parent-task completion derived from subtasks and
  project completion derived from top-level tasks. Use canonical estimated
  hours so estimates entered in hours and mandays are comparable.
- If any task in an aggregation group is missing an estimate or has a zero
  estimate, use the existing equal-weight average for that entire group.
  This keeps legacy and partially estimated work represented and avoids
  silently ignoring tasks.
- Preserve the existing rule that a task without subtasks can have its
  completion percentage entered manually, while a task with subtasks derives
  its completion from those subtasks.
- Do not count a parent's estimate and its descendants as separate work in a
  single aggregation. The parent's estimate weights that task when its parent
  group is calculated; its children determine its own derived completion.
- Keep project and dashboard completion displays, task/subtask progress
  displays, and client read-only project views consistent with the same
  server-side calculation. Do not introduce a separate client-side formula.
- Preserve project-level behavior when a project has no tasks, and avoid
  division by zero for empty groups or zero total effort.

## Acceptance Criteria

- With tasks at 0% and 100% completion and positive estimates of 1 and 3
  hours, respectively, the weighted completion is 75%.
- Estimates of 1 manday and 8 hours have equal weight.
- A parent with fully estimated subtasks reports their effort-weighted
  completion; if any subtask estimate is missing or zero, it uses the existing
  equal average for those subtasks.
- A project's top-level tasks use the same weighted/fallback rule, and
  subtasks are not counted again in the project calculation.
- Existing and partially estimated projects continue to show completion using
  the equal-average fallback rather than losing unestimated tasks from the
  result.
- All relevant project, task, dashboard, and client read-only displays agree
  on completion, and automated tests cover weighted, fallback, empty, and
  zero-estimate cases.
