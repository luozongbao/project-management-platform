-- Function to calculate task completion percentage based on subtasks
DELIMITER //

CREATE FUNCTION CalculateTaskCompletion(task_id INT) 
RETURNS DECIMAL(5,2)
READS SQL DATA
DETERMINISTIC
BEGIN
    DECLARE subtask_count INT DEFAULT 0;
    DECLARE total_completion DECIMAL(10,2) DEFAULT 0;
    DECLARE calculated_percentage DECIMAL(5,2) DEFAULT 0;
    
    -- Count subtasks for this task
    SELECT COUNT(*) INTO subtask_count
    FROM tasks 
    WHERE parent_task_id = task_id;
    
    -- If no subtasks, return the task's own completion percentage
    IF subtask_count = 0 THEN
        SELECT completion_percentage INTO calculated_percentage
        FROM tasks 
        WHERE id = task_id;
        RETURN calculated_percentage;
    END IF;
    
    -- Calculate average completion of all subtasks (recursive)
    SELECT AVG(CalculateTaskCompletion(id)) INTO calculated_percentage
    FROM tasks 
    WHERE parent_task_id = task_id;
    
    RETURN COALESCE(calculated_percentage, 0);
END//

DELIMITER ;

-- Trigger to update parent task completion when subtask changes
DELIMITER //

CREATE TRIGGER UpdateParentTaskCompletion
AFTER UPDATE ON tasks
FOR EACH ROW
BEGIN
    DECLARE parent_id INT;
    DECLARE new_completion DECIMAL(5,2);
    
    -- Get the parent task ID
    SET parent_id = NEW.parent_task_id;
    
    -- If this task has a parent, update the parent's completion
    IF parent_id IS NOT NULL THEN
        -- Calculate new completion percentage for parent
        SET new_completion = CalculateTaskCompletion(parent_id);
        
        -- Update parent task completion percentage
        UPDATE tasks 
        SET completion_percentage = new_completion,
            updated_at = NOW()
        WHERE id = parent_id;
    END IF;
END//

DELIMITER ;

-- Trigger to update parent task completion when new subtask is inserted
DELIMITER //

CREATE TRIGGER UpdateParentTaskCompletionInsert
AFTER INSERT ON tasks
FOR EACH ROW
BEGIN
    DECLARE parent_id INT;
    DECLARE new_completion DECIMAL(5,2);
    
    -- Get the parent task ID
    SET parent_id = NEW.parent_task_id;
    
    -- If this task has a parent, update the parent's completion
    IF parent_id IS NOT NULL THEN
        -- Calculate new completion percentage for parent
        SET new_completion = CalculateTaskCompletion(parent_id);
        
        -- Update parent task completion percentage
        UPDATE tasks 
        SET completion_percentage = new_completion,
            updated_at = NOW()
        WHERE id = parent_id;
    END IF;
END//

DELIMITER ;

-- Trigger to update parent task completion when subtask is deleted
DELIMITER //

CREATE TRIGGER UpdateParentTaskCompletionDelete
AFTER DELETE ON tasks
FOR EACH ROW
BEGIN
    DECLARE parent_id INT;
    DECLARE new_completion DECIMAL(5,2);
    
    -- Get the parent task ID
    SET parent_id = OLD.parent_task_id;
    
    -- If this task had a parent, update the parent's completion
    IF parent_id IS NOT NULL THEN
        -- Calculate new completion percentage for parent
        SET new_completion = CalculateTaskCompletion(parent_id);
        
        -- Update parent task completion percentage
        UPDATE tasks 
        SET completion_percentage = new_completion,
            updated_at = NOW()
        WHERE id = parent_id;
    END IF;
END//

DELIMITER ;