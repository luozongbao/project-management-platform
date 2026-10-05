# Add Task Time Estimates

**Status:** Open  
**Type:** Feature / Data Model  
**Depends on:** None  
**Implementation order:** 1 of 2

## Problem

Task completion is currently expressed as a percentage, but tasks have no
effort estimate. Without task time, completion rollups treat every task as
equally significant regardless of how much work it represents.

## Requirements

- Add an optional total-time estimate to each task, including subtasks.
- Let users enter the estimate in hours or mandays. One manday is exactly
  8 hours.
- Store and use one canonical value in the database: estimated hours. Convert
  mandays to hours when saving and convert hours to the selected unit when
  displaying or editing.
- Preserve existing tasks without estimates; a schema migration must not
  assign fabricated estimates or change existing completion values.
- Validate estimates as finite, non-negative numbers. Reject malformed,
  negative, or overflowing values with the application's normal validation
  feedback. An empty estimate means “not estimated”; zero is not a usable
  weight for weighted completion.
- Add the time estimate and unit selector to the task and subtask create/edit
  forms, and show the estimate in the task list and task/subtask detail views.
- Follow existing authorization and task ownership rules when reading or
  updating estimates.
- Preserve existing date, status, and completion editing behavior.

## Acceptance Criteria

- A user can save and edit a task estimate in hours or mandays, and the value
  is consistently represented as hours in storage.
- Entering 1 manday and entering 8 hours produce the same stored estimate.
- Existing tasks and subtasks remain usable and show an empty estimate until a
  user supplies one.
- Invalid, negative, or non-finite values are rejected with a visible
  validation message and do not overwrite the saved estimate.
- Users cannot read or modify estimates for tasks they are not authorized to
  access.
- Database migration and rollback/deployment behavior follow the project's
  existing migration conventions.
