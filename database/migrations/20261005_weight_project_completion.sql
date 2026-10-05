-- Updates project_stats to apply effort weighting to top-level task completion.
-- Task parent progress is maintained by the application; application pages also
-- calculate effective nested progress from the task tree before rendering.
-- Safe to re-run: CREATE OR REPLACE VIEW is idempotent.

CREATE OR REPLACE VIEW project_stats AS
SELECT
    p.id,
    p.name,
    p.status,
    p.expected_completion_date,
    p.completion_date,
    COUNT(DISTINCT t.id) as total_tasks,
    COUNT(DISTINCT CASE WHEN t.status = 'completed' THEN t.id END) as completed_tasks,
    COUNT(DISTINCT CASE WHEN t.status != 'completed' THEN t.id END) as uncompleted_tasks,
    COALESCE((
        SELECT CASE
            WHEN COUNT(*) = 0 THEN 0
            WHEN SUM(CASE WHEN top_task.estimated_hours IS NULL OR top_task.estimated_hours <= 0 THEN 1 ELSE 0 END) = 0
                THEN SUM(top_task.estimated_hours * top_task.completion_percentage) / NULLIF(SUM(top_task.estimated_hours), 0)
            ELSE AVG(top_task.completion_percentage)
        END
        FROM tasks top_task
        WHERE top_task.project_id = p.id
          AND top_task.parent_task_id IS NULL
    ), 0) as avg_completion_percentage,
    COUNT(DISTINCT pc.contact_id) as contact_count
FROM projects p
LEFT JOIN tasks t ON p.id = t.project_id
LEFT JOIN project_contacts pc ON p.id = pc.project_id
GROUP BY p.id, p.name, p.status, p.expected_completion_date, p.completion_date;
