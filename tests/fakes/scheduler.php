<?php
// Action Scheduler, in memory. Every scheduling call is a write, logged with
// the request it happened in; agm_run_actions() plays the queue runner.
//
// As in the real one, an action is pending until the runner claims it, then
// in-progress while its callback runs, and as_has_scheduled_action() counts
// both.

$GLOBALS['agm']['as']      = array();
$GLOBALS['agm']['as_next'] = 1;

function agm_as_add($type, $timestamp, $hook, $args, $group, $interval = null) {
    $id = $GLOBALS['agm']['as_next']++;
    $GLOBALS['agm']['as'][$id] = array(
        'id'        => $id,
        'type'      => $type,
        'timestamp' => (int) $timestamp,
        'interval'  => $interval,
        'hook'      => $hook,
        'args'      => (array) $args,
        'group'     => (string) $group,
        'status'    => 'pending',
    );
    agm_record_write('as', $hook, $type);
    return $id;
}
/** An empty hook or group matches any; null args match any args. */
function agm_as_matches($action, $hook, $args, $group) {
    if ($hook !== '' && $hook !== null && $action['hook'] !== $hook) {
        return false;
    }
    if ($group !== '' && $group !== null && $action['group'] !== $group) {
        return false;
    }
    return $args === null || $action['args'] === (array) $args;
}
function as_enqueue_async_action($hook, $args = array(), $group = '', $unique = false, $priority = 10) {
    if ($unique && as_has_scheduled_action($hook, $args, $group)) {
        return 0;
    }
    return agm_as_add('async', time(), $hook, $args, $group);
}
function as_schedule_single_action($timestamp, $hook, $args = array(), $group = '', $unique = false, $priority = 10) {
    if ($unique && as_has_scheduled_action($hook, $args, $group)) {
        return 0;
    }
    return agm_as_add('single', $timestamp, $hook, $args, $group);
}
function as_schedule_recurring_action($timestamp, $interval, $hook, $args = array(), $group = '', $unique = false, $priority = 10) {
    if ($unique && as_has_scheduled_action($hook, $args, $group)) {
        return 0;
    }
    return agm_as_add('recurring', $timestamp, $hook, $args, $group, (int) $interval);
}
function as_has_scheduled_action($hook, $args = null, $group = '') {
    foreach ($GLOBALS['agm']['as'] as $action) {
        if (in_array($action['status'], array('pending', 'in-progress'), true) && agm_as_matches($action, $hook, $args, $group)) {
            return true;
        }
    }
    return false;
}
function as_next_scheduled_action($hook, $args = null, $group = '') {
    foreach ($GLOBALS['agm']['as'] as $action) {
        if (in_array($action['status'], array('pending', 'in-progress'), true) && agm_as_matches($action, $hook, $args, $group)) {
            return $action['status'] === 'in-progress' || $action['type'] === 'async' ? true : $action['timestamp'];
        }
    }
    return false;
}
function as_unschedule_action($hook, $args = array(), $group = '') {
    foreach ($GLOBALS['agm']['as'] as $id => $action) {
        if (agm_as_matches($action, $hook, $args, $group)) {
            unset($GLOBALS['agm']['as'][$id]);
            agm_record_write('as', $action['hook'], 'unschedule');
            return $id;
        }
    }
    return null;
}
function as_unschedule_all_actions($hook, $args = array(), $group = '') {
    $GLOBALS['agm']['as_unschedule_all'][] = array('hook' => $hook, 'args' => $args, 'group' => $group);
    foreach ($GLOBALS['agm']['as'] as $id => $action) {
        if (agm_as_matches($action, $hook, $args === array() ? null : $args, $group)) {
            unset($GLOBALS['agm']['as'][$id]);
        }
    }
    agm_record_write('as', (string) $hook, 'unschedule_all');
}
function as_get_scheduled_actions($args = array(), $return_format = 'OBJECT') {
    $found = array_values(array_filter($GLOBALS['agm']['as'], function ($action) use ($args) {
        $statuses = isset($args['status']) && $args['status'] !== '' ? (array) $args['status'] : null;
        return (!isset($args['hook']) || $action['hook'] === $args['hook'])
            && (!isset($args['group']) || $action['group'] === $args['group'])
            && ($statuses === null || in_array($action['status'], $statuses, true));
    }));
    if (isset($args['per_page']) && (int) $args['per_page'] > 0) {
        $found = array_slice($found, 0, (int) $args['per_page']);
    }
    return $return_format === 'ids' ? array_column($found, 'id') : $found;
}

/** The actions scheduled for a hook (in group agumail unless said otherwise). */
function agm_scheduled($hook, $group = 'agumail') {
    return array_values(array_filter($GLOBALS['agm']['as'], function ($action) use ($hook, $group) {
        return $action['hook'] === $hook && ($group === null || $action['group'] === $group);
    }));
}

/**
 * Plays the queue runner for one hook: in a cron request, claims each pending
 * action (in-progress while its callback runs) and fires the hook the way
 * Action Scheduler does. One-off actions then leave the queue; recurring ones
 * are pending again.
 */
function agm_run_actions($hook) {
    $ran = 0;
    foreach (array_keys($GLOBALS['agm']['as']) as $id) {
        $action = isset($GLOBALS['agm']['as'][$id]) ? $GLOBALS['agm']['as'][$id] : null;
        if (!$action || $action['hook'] !== $hook || $action['status'] !== 'pending') {
            continue;
        }
        $GLOBALS['agm']['as'][$id]['status'] = 'in-progress';
        try {
            do_action_ref_array($hook, $action['args']);
        } finally {
            if ($action['type'] === 'recurring') {
                $GLOBALS['agm']['as'][$id]['status'] = 'pending';
            } else {
                unset($GLOBALS['agm']['as'][$id]);
            }
        }
        $ran++;
    }
    return $ran;
}
