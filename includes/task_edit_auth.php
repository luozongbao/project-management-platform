<?php
/**
 * Authorization helpers for task_edit.php — issue-022 (IDOR fix).
 *
 * Three narrow checks the wizard runs on every POST before it accepts a
 * responsible_person_id, a contact_person_id, or a project_id from the
 * client. Each returns a boolean; the caller appends the human-readable
 * error to $errors[] on false.
 *
 * The rules are intentionally narrower than "row exists": a logged-in
 * attacker can otherwise POST any user id / contact id / project id and
 * the wizard's existence-only checks would accept it. See
 * docs/issues/issue-022.md for the full attack model and rationale.
 *
 * Data model assumption: there is no project_members table today, so
 * "the responsible person is a member of this project" cannot be
 * enforced. The current rule is "the responsible person is either the
 * current user, or another user who is the responsible person of at
 * least one project" — i.e. another authenticated "self". This is the
 * narrowest bound the existing schema can support; a v2.0 add will
 * tighten it to true project membership.
 */

require_once __DIR__ . '/Database.php';

if (!function_exists('taskEditCanAssignResponsible')) {
    /**
     * Can the current user ($user_id) assign a task to $responsible_person_id?
     *
     * The rule: $responsible_person_id must equal $user_id, OR it must
     * be the responsible_person_id of at least one project (i.e. be
     * another project-owning user, not a non-owner registered user).
     */
    function taskEditCanAssignResponsible($db, $user_id, $responsible_person_id) {
        $rid = (int)$responsible_person_id;
        $uid = (int)$user_id;
        if ($rid <= 0 || $uid <= 0) {
            return false;
        }
        $allowed = $db->fetchOne(
            "SELECT 1 FROM users
             WHERE id = ?
               AND (id = ? OR id IN (
                    SELECT responsible_person_id FROM projects
                    WHERE responsible_person_id IS NOT NULL
               ))",
            [$rid, $uid]
        );
        return (bool)$allowed;
    }
}

if (!function_exists('taskEditCanAssignContact')) {
    /**
     * Can the current user ($user_id) link a task to $contact_person_id?
     *
     * The rule: the contact must be linked (via project_contacts) to at
     * least one project the current user owns. The query mirrors the
     * dropdown's project_contacts join so GET-time and POST-time views
     * match.
     */
    function taskEditCanAssignContact($db, $user_id, $contact_person_id) {
        $cid = (int)$contact_person_id;
        $uid = (int)$user_id;
        if ($cid <= 0 || $uid <= 0) {
            return false;
        }
        $allowed = $db->fetchOne(
            "SELECT 1 FROM contacts c
             JOIN project_contacts pc ON c.id = pc.contact_id
             JOIN projects p ON pc.project_id = p.id
             WHERE c.id = ? AND p.responsible_person_id = ?",
            [$cid, $uid]
        );
        return (bool)$allowed;
    }
}

if (!function_exists('taskEditCanUseProject')) {
    /**
     * Can the current user ($user_id) move a task into $project_id?
     *
     * The rule: $project_id must be a project whose
     * responsible_person_id is the current user. The dropdown is
     * already scoped ($user_projects in task_edit.php), so a normal
     * browser flow never trips this — it only catches a forged POST
     * that names a project the attacker does not own.
     */
    function taskEditCanUseProject($db, $user_id, $project_id) {
        $pid = (int)$project_id;
        $uid = (int)$user_id;
        if ($pid <= 0 || $uid <= 0) {
            return false;
        }
        $allowed = $db->fetchOne(
            "SELECT 1 FROM projects WHERE id = ? AND responsible_person_id = ?",
            [$pid, $uid]
        );
        return (bool)$allowed;
    }
}
