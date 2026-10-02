<?php
/**
 * Client portal localization (English / Simplified Chinese).
 *
 * Designed for the unauthenticated /client/ surface only. We deliberately
 * keep this separate from the internal app's strings to avoid regressing
 * the auth pages, and to make it easy to evolve the catalog independently.
 *
 * Usage:
 *   require_once __DIR__ . '/lang.php';
 *   $lang = client_current_lang();           // 'en' or 'zh'
 *   echo t('landing.title');                 // translated string
 *   echo t('greeting', ['name' => 'Alice']); // sprintf-style
 *
 * Locale resolution: ?lang= override → cookie → Accept-Language → 'en'.
 */

// Available locales. Add new ones here.
function client_supported_langs() {
    return ['en', 'zh'];
}

// Translation catalog. Keys are dot-separated namespaces.
function client_translations() {
    return [
        'en' => [
            // Landing page
            'landing.title'          => 'Client Portal',
            'landing.tagline'        => 'Client Project Portal',
            'landing.heading'        => 'Enter Your Project Code',
            'landing.intro'          => 'Paste the project code from your invitation email, or open the direct link your project manager sent you.',
            'landing.code_label'     => 'Project Code',
            'landing.code_placeholder' => 'AAAAA-AAAAA-AAAAA',
            'landing.code_help'      => 'Format: 5 characters, dash, 5, dash, 5.',
            'landing.submit'         => 'View My Project',
            'landing.foot'           => 'Lost your code? Please contact your project manager.',
            'landing.try_again'      => 'Try another code',
            'landing.lang_label'     => 'Language',
            // Errors
            'err.empty_code'         => 'Please enter your project code.',
            'err.bad_format'         => "That doesn't look like a valid project code. Codes look like AX498-99ZB2-92C3J.",
            'err.no_project'         => 'No project found for that code. Please double-check it.',
            'err.bad_format_dash'    => 'Invalid project code format.',

            // Dashboard
            'dash.title_suffix'      => 'Client Portal',
            'dash.brand_suffix'      => 'Client Portal',
            'dash.code_label'        => 'Code',
            'dash.overall'           => 'Overall Completion',
            'dash.overall_note'      => 'Average of all top-level task completion percentages.',
            'dash.project_info'      => 'Project Info',
            'dash.started'           => 'Started',
            'dash.expected'          => 'Expected',
            'dash.completed'         => 'Completed',
            'dash.not_set'           => 'Not set',
            'dash.in_progress'       => 'In progress',
            'dash.manager'           => 'Project manager',
            'dash.budget'            => 'Budget',
            'dash.budget_none'       => 'Not specified.',
            'dash.counts'            => 'Counts',
            'dash.tasks'             => 'Tasks',
            'dash.completed_count'   => 'Completed',
            'dash.open'              => 'Open',
            'dash.contacts'          => 'Contacts',
            'dash.scope'             => 'Project Scope',
            'dash.scope_none'        => 'No project scope has been published yet.',
            'dash.tasks_heading'     => 'Tasks & Progress',
            'dash.total_suffix'      => 'total',
            'dash.no_tasks'          => 'No tasks have been created yet.',
            'dash.last_updated'      => 'Last updated',
            'dash.contact_pm'        => 'Have a question? Please contact your project manager.',
            'dash.task_due'          => 'Due',
            'dash.task_completed'    => 'Completed',

            // Statuses
            'status.not_started'     => 'Not started',
            'status.in_progress'     => 'In progress',
            'status.completed'       => 'Completed',
            'status.on_hold'         => 'On hold',
        ],
        'zh' => [
            // Landing page
            'landing.title'          => '客户门户',
            'landing.tagline'        => '客户项目门户',
            'landing.heading'        => '请输入您的项目代码',
            'landing.intro'          => '请粘贴邀请邮件中的项目代码,或打开项目经理发给您的链接。',
            'landing.code_label'     => '项目代码',
            'landing.code_placeholder' => 'AAAAA-AAAAA-AAAAA',
            'landing.code_help'      => '格式: 5 个字符、连字符、5 个、连字符、5 个。',
            'landing.submit'         => '查看我的项目',
            'landing.foot'           => '找不到代码?请联系您的项目经理。',
            'landing.try_again'      => '重新输入代码',
            'landing.lang_label'     => '语言',
            // Errors
            'err.empty_code'         => '请输入您的项目代码。',
            'err.bad_format'         => '代码格式不正确,示例:AX498-99ZB2-92C3J。',
            'err.no_project'         => '未找到与此代码关联的项目,请核对后重试。',
            'err.bad_format_dash'    => '项目代码格式无效。',

            // Dashboard
            'dash.title_suffix'      => '客户门户',
            'dash.brand_suffix'      => '客户门户',
            'dash.code_label'        => '代码',
            'dash.overall'           => '总体进度',
            'dash.overall_note'      => '所有顶级任务完成率的平均值。',
            'dash.project_info'      => '项目信息',
            'dash.started'           => '开始日期',
            'dash.expected'          => '预计完成',
            'dash.completed'         => '已完成',
            'dash.not_set'           => '未设置',
            'dash.in_progress'       => '进行中',
            'dash.manager'           => '项目经理',
            'dash.budget'            => '预算',
            'dash.budget_none'       => '暂未设置。',
            'dash.counts'            => '统计',
            'dash.tasks'             => '任务',
            'dash.completed_count'   => '已完成',
            'dash.open'              => '进行中',
            'dash.contacts'          => '联系人',
            'dash.scope'             => '项目范围',
            'dash.scope_none'        => '项目范围尚未发布。',
            'dash.tasks_heading'     => '任务与进度',
            'dash.total_suffix'      => '项',
            'dash.no_tasks'          => '暂无任务。',
            'dash.last_updated'      => '最后更新',
            'dash.contact_pm'        => '如有疑问,请联系您的项目经理。',
            'dash.task_due'          => '截止',
            'dash.task_completed'    => '已完成',

            // Statuses
            'status.not_started'     => '未开始',
            'status.in_progress'     => '进行中',
            'status.completed'       => '已完成',
            'status.on_hold'         => '已暂停',
        ],
    ];
}

// Detect the active locale using URL override > cookie > Accept-Language > 'en'.
function client_current_lang() {
    static $cached_lang = null;
    if ($cached_lang !== null) {
        return $cached_lang;
    }
    $supported = client_supported_langs();

    // 1. Explicit override.
    $override = isset($_GET['lang']) ? strtolower(trim((string)$_GET['lang'])) : '';
    if (in_array($override, $supported, true)) {
        $cached_lang = $override;
        return $cached_lang;
    }

    // 2. Cookie.
    if (!empty($_COOKIE['client_lang']) && in_array(strtolower((string)$_COOKIE['client_lang']), $supported, true)) {
        $cached_lang = strtolower((string)$_COOKIE['client_lang']);
        return $cached_lang;
    }

    // 3. Accept-Language header.
    if (!empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        $al = strtolower((string)$_SERVER['HTTP_ACCEPT_LANGUAGE']);
        // Match Chinese variants → 'zh'. English → 'en'. First supported tag wins.
        if (preg_match('/\bzh\b/', $al)) {
            $cached_lang = 'zh';
            return $cached_lang;
        }
        if (preg_match('/\ben\b/', $al)) {
            $cached_lang = 'en';
            return $cached_lang;
        }
    }

    $cached_lang = 'en';
    return $cached_lang;
}

// Persist the choice via cookie (1 year, root path).
function client_set_lang_cookie($lang) {
    if (!in_array($lang, client_supported_langs(), true)) {
        return;
    }
    setcookie('client_lang', $lang, time() + 365 * 24 * 60 * 60, '/', '', false, true);
    $_COOKIE['client_lang'] = $lang;
}

// Translate a key. Optional $vars do sprintf-style substitution.
function t($key, $vars = []) {
    $lang = client_current_lang();
    $dict = client_translations();
    $str = $dict[$lang][$key] ?? $dict['en'][$key] ?? $key;
    if (!empty($vars)) {
        $str = strtr($str, $vars);
    }
    return $str;
}

// Translate a task_status enum value ('not_started' / 'in_progress' / 'completed' / 'on_hold').
function t_status($status) {
    $key = 'status.' . $status;
    $translated = t($key);
    // If translation missing (returns key verbatim), fall back to ucfirst.
    if ($translated === $key) {
        return ucfirst(str_replace('_', ' ', (string)$status));
    }
    return $translated;
}

// Other locales for the language switcher.
function client_other_langs() {
    $current = client_current_lang();
    $all = ['en' => 'English', 'zh' => '中文'];
    $out = [];
    foreach ($all as $code => $label) {
        if ($code !== $current) {
            $out[$code] = $label;
        }
    }
    return $out;
}

// Append (or replace) the lang parameter on a URL while preserving other params.
function client_lang_url($target_code) {
    $params = $_GET;
    $params['lang'] = $target_code;
    return '?' . http_build_query($params);
}