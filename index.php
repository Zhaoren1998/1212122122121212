<?php
declare(strict_types=1);



const a=12；

const DATA_FILE = __DIR__ . '/data/tasks.json';
const DB_FILE = __DIR__ . '/data/tasks.sqlite';
const LOG_FILE = __DIR__ . '/logs/app.log';
const MAX_TITLE_LENGTH = 80;
const MAX_CONTENT_LENGTH = 1000;
const MAX_SEARCH_KEYWORD_LENGTH = 120;
const MAX_CATEGORY_NAME_LENGTH = 40;
const MIN_DUE_AT = '2000-01-01 00:00:00';
const MAX_DUE_AT = '2100-12-31 23:59:00';
const MIN_REMIND_AT = '2000-01-01 00:00:00';
const MAX_REMIND_AT = '2100-12-31 23:59:00';
const ALLOWED_STATUSES = ['未开始', '进行中', '已完成', '已归档'];
const ALLOWED_PRIORITIES = ['高', '中', '低'];
const DEFAULT_TASK_PRIORITY = '中';
const ALLOWED_REPEAT_TYPES = ['daily', 'weekly', 'monthly', 'yearly'];
const MIN_REPEAT_INTERVAL = 1;
const MAX_REPEAT_INTERVAL = 99;
const REPEAT_TYPE_LABELS = [
    'daily' => '每天',
    'weekly' => '每周',
    'monthly' => '每月',
    'yearly' => '每年',
];
const LEGACY_STATUS_MAP = [
    '待处理' => '未开始',
];
const MAX_TAG_NAME_LENGTH = 30;
const MAX_TAG_INPUT_LENGTH = 200;
const MAX_COMMENT_CONTENT_LENGTH = 500;
const ALLOWED_TAG_COLORS = ['#ef4444', '#f97316', '#eab308', '#22c55e', '#14b8a6', '#3b82f6', '#8b5cf6', '#ec4899', '#667085', '#2563eb'];
const ALLOWED_SORT_FIELDS = ['created_at', 'updated_at', 'due_at', 'priority', 'status', 'title'];
const ALLOWED_SORT_ORDERS = ['asc', 'desc'];
const TASK_VISIBILITY_ACTIVE = 'active';
const TASK_VISIBILITY_ARCHIVED = 'archived';
const TASK_VISIBILITY_ALL = 'all';
const TASK_VISIBILITY_TRASH = 'trash';
const DEFAULT_SORT_FIELD = 'created_at';
const DEFAULT_SORT_ORDER = 'desc';
const DEFAULT_PAGE_SIZE = 20;
const MAX_PAGE_SIZE = 100;
const MIN_PAGE_SIZE = 5;
const DEFAULT_PAGE_NUMBER = 1;
const DASHBOARD_UPCOMING_DAYS = 7;
const DASHBOARD_UPCOMING_LIMIT = 5;
const CALENDAR_SUMMARY_LIMIT = 3;
const MAX_ATTACHMENT_FILE_SIZE = 10 * 1024 * 1024;
const MAX_ATTACHMENT_FILE_NAME_LENGTH = 255;
const ALLOWED_ATTACHMENT_MIME_TYPES = [
    'image/jpeg',
    'image/png',
    'image/gif',
    'image/webp',
    'image/svg+xml',
    'application/pdf',
    'text/plain',
    'text/csv',
    'application/json',
    'application/xml',
    'application/zip',
    'application/x-rar-compressed',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
];
const CSV_IMPORT_MAX_FILE_SIZE = 5 * 1024 * 1024;
const CSV_IMPORT_MAX_ROWS = 1000;
const CSV_IMPORT_REQUIRED_COLUMNS = ['title'];
const CSV_IMPORT_ALL_COLUMNS = ['title', 'content', 'status', 'priority', 'category', 'tags', 'due_at'];
const BACKUP_DIR = __DIR__ . '/data/backups';
const BACKUP_PREFIX = 'tasks_backup_';
const BACKUP_FILE_EXTENSION = '.sqlite';
const MAX_BACKUP_FILES = 10;
const BACKUP_VERSION = '1.0';
const MAX_RESTORE_FILE_SIZE = 100 * 1024 * 1024;
const SETTING_KEY_DEFAULT_SORT_FIELD = 'default_sort_field';
const SETTING_KEY_DEFAULT_SORT_ORDER = 'default_sort_order';
const SETTING_KEY_DEFAULT_PAGE_SIZE = 'default_page_size';
const SETTING_KEY_DEFAULT_PRIORITY = 'default_priority';
const SETTING_KEY_REMINDER_LEAD_TIME = 'reminder_lead_time';
const DEFAULT_REMINDER_LEAD_TIME = 30;
const MIN_REMINDER_LEAD_TIME = 0;
const MAX_REMINDER_LEAD_TIME = 1440;

date_default_timezone_set('Asia/Shanghai');

function writeDebugLog(string $operation, array $parameters, string $status, array $context = []): void
{
    $logDirectory = dirname(LOG_FILE);
    if (!is_dir($logDirectory)) {
        mkdir($logDirectory, 0775, true);
    }

    $entry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'operation' => $operation,
        'parameters' => $parameters,
        'status' => $status,
        'context' => $context,
    ];

    file_put_contents(
        LOG_FILE,
        json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
    persistDebugLogToDatabase($entry);
}

function persistDebugLogToDatabase(array $entry): void
{
    if (!empty($GLOBALS['isPersistingDebugLog']) || !empty($GLOBALS['suppressDatabaseDebugLog']) || !extension_loaded('pdo_sqlite')) {
        return;
    }

    $GLOBALS['isPersistingDebugLog'] = true;

    try {
        $dataDirectory = dirname(DB_FILE);
        if (!is_dir($dataDirectory) && !mkdir($dataDirectory, 0775, true) && !is_dir($dataDirectory)) {
            return;
        }

        $pdo = new PDO('sqlite:' . DB_FILE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec("CREATE TABLE IF NOT EXISTS operation_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            operation TEXT NOT NULL,
            parameters_json TEXT NOT NULL DEFAULT '{}',
            status TEXT NOT NULL,
            context_json TEXT NOT NULL DEFAULT '{}',
            created_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
        )");

        $parametersJson = json_encode($entry['parameters'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $contextJson = json_encode($entry['context'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $statement = $pdo->prepare(
            'INSERT INTO operation_logs (operation, parameters_json, status, context_json, created_at)
            VALUES (:operation, :parameters_json, :status, :context_json, :created_at)'
        );
        $statement->execute([
            ':operation' => (string) ($entry['operation'] ?? 'unknown'),
            ':parameters_json' => is_string($parametersJson) ? $parametersJson : '{}',
            ':status' => (string) ($entry['status'] ?? 'unknown'),
            ':context_json' => is_string($contextJson) ? $contextJson : '{}',
            ':created_at' => (string) ($entry['timestamp'] ?? date('Y-m-d H:i:s')),
        ]);
    } catch (Throwable $exception) {
        $GLOBALS['lastDebugLogDatabaseError'] = $exception->getMessage();
    } finally {
        $GLOBALS['isPersistingDebugLog'] = false;
    }
}

function escapeHtml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function normalizeTaskStatus(string $status, string $fallback = '未开始'): string
{
    $trimmedStatus = trim($status);
    if (isset(LEGACY_STATUS_MAP[$trimmedStatus])) {
        return LEGACY_STATUS_MAP[$trimmedStatus];
    }

    if (in_array($trimmedStatus, ALLOWED_STATUSES, true)) {
        return $trimmedStatus;
    }

    return in_array($fallback, ALLOWED_STATUSES, true) ? $fallback : '未开始';
}

function isAllowedTaskStatus(string $status): bool
{
    return in_array(trim($status), ALLOWED_STATUSES, true);
}

function normalizeTaskPriority(string $priority, string $fallback = DEFAULT_TASK_PRIORITY): string
{
    $trimmedPriority = trim($priority);
    if (in_array($trimmedPriority, ALLOWED_PRIORITIES, true)) {
        return $trimmedPriority;
    }

    return in_array($fallback, ALLOWED_PRIORITIES, true) ? $fallback : DEFAULT_TASK_PRIORITY;
}

function isAllowedTaskPriority(string $priority): bool
{
    return in_array(trim($priority), ALLOWED_PRIORITIES, true);
}

function getPriorityBadgeClass(string $priority): string
{
    $normalizedPriority = normalizeTaskPriority($priority);
    if ($normalizedPriority === '高') {
        return 'priority-high';
    }
    if ($normalizedPriority === '低') {
        return 'priority-low';
    }

    return 'priority-medium';
}

function normalizeStoredDueAt($dueAt): string
{
    if (!is_string($dueAt)) {
        return '';
    }

    $trimmedDueAt = trim($dueAt);
    if ($trimmedDueAt === '') {
        return '';
    }

    $dateTime = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $trimmedDueAt);
    $dateErrors = DateTimeImmutable::getLastErrors();
    if ($dateTime instanceof DateTimeImmutable && $dateErrors === false) {
        return $dateTime->format('Y-m-d H:i:s');
    }

    $timestamp = strtotime($trimmedDueAt);
    if ($timestamp === false) {
        return '';
    }

    return date('Y-m-d H:i:s', $timestamp);
}

function validateDueAtInput(string $dueAtInput): array
{
    $trimmedDueAt = trim($dueAtInput);
    if ($trimmedDueAt === '') {
        writeDebugLog('task_due_at_validation', [
            'submitted_due_at' => $dueAtInput,
        ], 'success', [
            'result' => 'empty_due_at_allowed',
            'normalized_due_at' => '',
        ]);
        return [
            'valid' => true,
            'normalized' => '',
            'error' => '',
            'reason' => 'empty_due_at_allowed',
        ];
    }

    $dateTime = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $trimmedDueAt);
    $dateErrors = DateTimeImmutable::getLastErrors();
    if (!$dateTime instanceof DateTimeImmutable || $dateErrors !== false) {
        writeDebugLog('task_due_at_validation', [
            'submitted_due_at' => $dueAtInput,
        ], 'failed', [
            'reason' => 'invalid_datetime_format',
            'expected_format' => 'Y-m-dTH:i',
            'database_write_blocked' => true,
        ]);
        return [
            'valid' => false,
            'normalized' => '',
            'error' => '截止日期时间格式无效，请使用页面日期时间控件选择。',
            'reason' => 'invalid_datetime_format',
        ];
    }

    $minDateTime = new DateTimeImmutable(MIN_DUE_AT);
    $maxDateTime = new DateTimeImmutable(MAX_DUE_AT);
    if ($dateTime < $minDateTime || $dateTime > $maxDateTime) {
        writeDebugLog('task_due_at_validation', [
            'submitted_due_at' => $dueAtInput,
            'normalized_due_at' => $dateTime->format('Y-m-d H:i:s'),
        ], 'failed', [
            'reason' => 'datetime_out_of_allowed_range',
            'min_due_at' => MIN_DUE_AT,
            'max_due_at' => MAX_DUE_AT,
            'database_write_blocked' => true,
        ]);
        return [
            'valid' => false,
            'normalized' => '',
            'error' => '截止日期时间必须在 2000-01-01 00:00 到 2100-12-31 23:59 之间。',
            'reason' => 'datetime_out_of_allowed_range',
        ];
    }

    $normalizedDueAt = $dateTime->format('Y-m-d H:i:s');
    writeDebugLog('task_due_at_validation', [
        'submitted_due_at' => $dueAtInput,
        'normalized_due_at' => $normalizedDueAt,
    ], 'success', [
        'result' => 'valid_due_at',
        'min_due_at' => MIN_DUE_AT,
        'max_due_at' => MAX_DUE_AT,
    ]);

    return [
        'valid' => true,
        'normalized' => $normalizedDueAt,
        'error' => '',
        'reason' => 'valid_due_at',
    ];
}

function formatDueAtForInput(string $dueAt): string
{
    $normalizedDueAt = normalizeStoredDueAt($dueAt);
    if ($normalizedDueAt === '') {
        return '';
    }

    $timestamp = strtotime($normalizedDueAt);
    return $timestamp === false ? '' : date('Y-m-d\TH:i', $timestamp);
}

function normalizeStoredRemindAt($remindAt): string
{
    if (!is_string($remindAt)) {
        return '';
    }

    $trimmedRemindAt = trim($remindAt);
    if ($trimmedRemindAt === '') {
        return '';
    }

    $dateTime = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $trimmedRemindAt);
    $dateErrors = DateTimeImmutable::getLastErrors();
    if ($dateTime instanceof DateTimeImmutable && $dateErrors === false) {
        return $dateTime->format('Y-m-d H:i:s');
    }

    $timestamp = strtotime($trimmedRemindAt);
    if ($timestamp === false) {
        return '';
    }

    return date('Y-m-d H:i:s', $timestamp);
}

function validateRemindAtInput(string $remindAtInput, string $dueAt = ''): array
{
    $trimmedRemindAt = trim($remindAtInput);
    if ($trimmedRemindAt === '') {
        writeDebugLog('task_remind_at_validation', [
            'submitted_remind_at' => $remindAtInput,
        ], 'success', [
            'result' => 'empty_remind_at_allowed',
            'normalized_remind_at' => '',
        ]);
        return [
            'valid' => true,
            'normalized' => '',
            'error' => '',
            'reason' => 'empty_remind_at_allowed',
        ];
    }

    $dateTime = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $trimmedRemindAt);
    $dateErrors = DateTimeImmutable::getLastErrors();
    if (!$dateTime instanceof DateTimeImmutable || $dateErrors !== false) {
        writeDebugLog('task_remind_at_validation', [
            'submitted_remind_at' => $remindAtInput,
        ], 'failed', [
            'reason' => 'invalid_datetime_format',
            'expected_format' => 'Y-m-dTH:i',
            'database_write_blocked' => true,
        ]);
        return [
            'valid' => false,
            'normalized' => '',
            'error' => '提醒时间格式无效，请使用页面日期时间控件选择。',
            'reason' => 'invalid_datetime_format',
        ];
    }

    $minDateTime = new DateTimeImmutable(MIN_REMIND_AT);
    $maxDateTime = new DateTimeImmutable(MAX_REMIND_AT);
    if ($dateTime < $minDateTime || $dateTime > $maxDateTime) {
        writeDebugLog('task_remind_at_validation', [
            'submitted_remind_at' => $remindAtInput,
            'normalized_remind_at' => $dateTime->format('Y-m-d H:i:s'),
        ], 'failed', [
            'reason' => 'datetime_out_of_allowed_range',
            'min_remind_at' => MIN_REMIND_AT,
            'max_remind_at' => MAX_REMIND_AT,
            'database_write_blocked' => true,
        ]);
        return [
            'valid' => false,
            'normalized' => '',
            'error' => '提醒时间必须在 2000-01-01 00:00 到 2100-12-31 23:59 之间。',
            'reason' => 'datetime_out_of_allowed_range',
        ];
    }

    $now = new DateTimeImmutable();
    if ($dateTime <= $now) {
        writeDebugLog('task_remind_at_validation', [
            'submitted_remind_at' => $remindAtInput,
            'normalized_remind_at' => $dateTime->format('Y-m-d H:i:s'),
        ], 'failed', [
            'reason' => 'remind_at_not_in_future',
            'current_time' => $now->format('Y-m-d H:i:s'),
            'database_write_blocked' => true,
        ]);
        return [
            'valid' => false,
            'normalized' => '',
            'error' => '提醒时间必须晚于当前时间。',
            'reason' => 'remind_at_not_in_future',
        ];
    }

    if ($dueAt !== '') {
        $normalizedDueAt = normalizeStoredDueAt($dueAt);
        if ($normalizedDueAt !== '') {
            $dueDateTime = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $normalizedDueAt);
            if ($dueDateTime instanceof DateTimeImmutable && $dateTime > $dueDateTime) {
                writeDebugLog('task_remind_at_validation', [
                    'submitted_remind_at' => $remindAtInput,
                    'normalized_remind_at' => $dateTime->format('Y-m-d H:i:s'),
                    'due_at' => $normalizedDueAt,
                ], 'failed', [
                    'reason' => 'remind_at_after_due_at',
                    'normalized_due_at' => $normalizedDueAt,
                    'database_write_blocked' => true,
                ]);
                return [
                    'valid' => false,
                    'normalized' => '',
                    'error' => '提醒时间不能晚于截止日期时间。',
                    'reason' => 'remind_at_after_due_at',
                ];
            }
        }
    }

    $normalizedRemindAt = $dateTime->format('Y-m-d H:i:s');
    writeDebugLog('task_remind_at_validation', [
        'submitted_remind_at' => $remindAtInput,
        'normalized_remind_at' => $normalizedRemindAt,
    ], 'success', [
        'result' => 'valid_remind_at',
        'min_remind_at' => MIN_REMIND_AT,
        'max_remind_at' => MAX_REMIND_AT,
    ]);

    return [
        'valid' => true,
        'normalized' => $normalizedRemindAt,
        'error' => '',
        'reason' => 'valid_remind_at',
    ];
}

function formatRemindAtForInput(string $remindAt): string
{
    $normalizedRemindAt = normalizeStoredRemindAt($remindAt);
    if ($normalizedRemindAt === '') {
        return '';
    }

    $timestamp = strtotime($normalizedRemindAt);
    return $timestamp === false ? '' : date('Y-m-d\TH:i', $timestamp);
}

function parseRepeatRule(string $repeatRule): array
{
    $trimmedRepeatRule = trim($repeatRule);
    if ($trimmedRepeatRule === '') {
        return [
            'type' => '',
            'interval' => 1,
            'end_date' => '',
        ];
    }

    $parts = explode('|', $trimmedRepeatRule);
    $type = isset($parts[0]) && in_array(trim($parts[0]), ALLOWED_REPEAT_TYPES, true) ? trim($parts[0]) : '';
    $interval = isset($parts[1]) && ctype_digit(trim($parts[1])) ? (int) trim($parts[1]) : 1;
    if ($interval < MIN_REPEAT_INTERVAL) {
        $interval = MIN_REPEAT_INTERVAL;
    }
    if ($interval > MAX_REPEAT_INTERVAL) {
        $interval = MAX_REPEAT_INTERVAL;
    }
    $endDate = isset($parts[2]) ? trim($parts[2]) : '';

    return [
        'type' => $type,
        'interval' => $interval,
        'end_date' => $endDate,
    ];
}

function buildRepeatRule(string $type, int $interval, string $endDate): string
{
    if ($type === '' || !in_array($type, ALLOWED_REPEAT_TYPES, true)) {
        return '';
    }

    $normalizedInterval = max(MIN_REPEAT_INTERVAL, min(MAX_REPEAT_INTERVAL, $interval));
    return $type . '|' . $normalizedInterval . '|' . trim($endDate);
}

function buildSubmittedRepeatRule(string $type, string $interval, string $endDate): string
{
    $trimmedType = trim($type);
    if ($trimmedType === '') {
        return '';
    }

    return $trimmedType . '|' . trim($interval) . '|' . trim($endDate);
}

function parseRepeatEndDate(string $endDate): ?DateTimeImmutable
{
    $trimmedEndDate = trim($endDate);
    if ($trimmedEndDate === '') {
        return null;
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmedEndDate) === 1) {
        $dateTime = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $trimmedEndDate . ' 23:59:59');
        $dateErrors = DateTimeImmutable::getLastErrors();
        return $dateTime instanceof DateTimeImmutable && $dateErrors === false ? $dateTime : null;
    }

    $dateTime = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $trimmedEndDate);
    $dateErrors = DateTimeImmutable::getLastErrors();
    if ($dateTime instanceof DateTimeImmutable && $dateErrors === false) {
        return $dateTime;
    }

    return null;
}

function validateRepeatRuleInput(string $repeatRule, string $dueAt = ''): array
{
    $trimmedRepeatRule = trim($repeatRule);
    if ($trimmedRepeatRule === '') {
        writeDebugLog('repeat_rule_validation', [
            'repeat_rule' => $repeatRule,
            'due_at' => $dueAt,
        ], 'success', [
            'result' => 'empty_repeat_rule_allowed',
        ]);
        return [
            'valid' => true,
            'normalized' => '',
            'error' => '',
            'reason' => 'empty_repeat_rule_allowed',
        ];
    }

    $parts = explode('|', $trimmedRepeatRule);
    $submittedType = isset($parts[0]) ? trim($parts[0]) : '';
    $submittedInterval = isset($parts[1]) ? trim($parts[1]) : '';
    $submittedEndDate = isset($parts[2]) ? trim($parts[2]) : '';

    if ($submittedType === '' || !in_array($submittedType, ALLOWED_REPEAT_TYPES, true)) {
        writeDebugLog('repeat_rule_validation', [
            'repeat_rule' => $repeatRule,
            'due_at' => $dueAt,
        ], 'failed', [
            'reason' => 'invalid_repeat_type',
            'allowed_types' => ALLOWED_REPEAT_TYPES,
            'database_write_blocked' => true,
        ]);
        return [
            'valid' => false,
            'normalized' => '',
            'error' => '重复类型无效，请选择每天、每周、每月或每年。',
            'reason' => 'invalid_repeat_type',
        ];
    }

    if ($submittedInterval === '' || !ctype_digit($submittedInterval)) {
        writeDebugLog('repeat_rule_validation', [
            'repeat_rule' => $repeatRule,
            'due_at' => $dueAt,
        ], 'failed', [
            'reason' => 'invalid_interval',
            'interval' => $submittedInterval,
            'database_write_blocked' => true,
        ]);
        return [
            'valid' => false,
            'normalized' => '',
            'error' => '重复间隔必须是 1 到 99 之间的整数。',
            'reason' => 'invalid_interval',
        ];
    }

    $interval = (int) $submittedInterval;
    if ($interval < MIN_REPEAT_INTERVAL || $interval > MAX_REPEAT_INTERVAL) {
        writeDebugLog('repeat_rule_validation', [
            'repeat_rule' => $repeatRule,
            'due_at' => $dueAt,
        ], 'failed', [
            'reason' => 'interval_out_of_allowed_range',
            'interval' => $interval,
            'min_interval' => MIN_REPEAT_INTERVAL,
            'max_interval' => MAX_REPEAT_INTERVAL,
            'database_write_blocked' => true,
        ]);
        return [
            'valid' => false,
            'normalized' => '',
            'error' => '重复间隔必须在 1 到 99 之间。',
            'reason' => 'interval_out_of_allowed_range',
        ];
    }

    if ($dueAt === '') {
        writeDebugLog('repeat_rule_validation', [
            'repeat_rule' => $repeatRule,
            'due_at' => $dueAt,
        ], 'failed', [
            'reason' => 'repeat_requires_due_at',
            'repeat_type' => $submittedType,
            'database_write_blocked' => true,
        ]);
        return [
            'valid' => false,
            'normalized' => '',
            'error' => '设置重复规则需要先指定截止日期。',
            'reason' => 'repeat_requires_due_at',
        ];
    }

    if ($submittedEndDate !== '') {
        $endDateTime = parseRepeatEndDate($submittedEndDate);

        if (!$endDateTime instanceof DateTimeImmutable) {
            writeDebugLog('repeat_rule_validation', [
                'repeat_rule' => $repeatRule,
                'due_at' => $dueAt,
            ], 'failed', [
                'reason' => 'invalid_end_date_format',
                'end_date' => $submittedEndDate,
                'database_write_blocked' => true,
            ]);
            return [
                'valid' => false,
                'normalized' => '',
                'error' => '重复结束日期格式无效。',
                'reason' => 'invalid_end_date_format',
            ];
        }

        if ($dueAt !== '') {
            $dueDateTime = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dueAt);
            $dateErrors = DateTimeImmutable::getLastErrors();
            if (!$dueDateTime instanceof DateTimeImmutable || $dateErrors !== false) {
                $dueDateTime = null;
            }
            if ($dueDateTime instanceof DateTimeImmutable && $endDateTime < $dueDateTime) {
                writeDebugLog('repeat_rule_validation', [
                    'repeat_rule' => $repeatRule,
                    'due_at' => $dueAt,
                ], 'failed', [
                    'reason' => 'end_date_before_start_date',
                    'end_date' => $submittedEndDate,
                    'due_at' => $dueAt,
                    'database_write_blocked' => true,
                ]);
                return [
                    'valid' => false,
                    'normalized' => '',
                    'error' => '重复结束日期不能早于任务截止日期。',
                    'reason' => 'end_date_before_start_date',
                ];
            }
        }
    }

    $normalizedRule = buildRepeatRule($submittedType, $interval, $submittedEndDate);
    writeDebugLog('repeat_rule_validation', [
        'repeat_rule' => $repeatRule,
        'due_at' => $dueAt,
        'normalized_rule' => $normalizedRule,
    ], 'success', [
        'result' => 'valid_repeat_rule',
        'type' => $submittedType,
        'interval' => $interval,
        'end_date' => $submittedEndDate,
    ]);

    return [
        'valid' => true,
        'normalized' => $normalizedRule,
        'error' => '',
        'reason' => 'valid_repeat_rule',
    ];
}

function calculateNextDueAt(string $currentDueAt, string $repeatRule): ?string
{
    $parsed = parseRepeatRule($repeatRule);
    if ($parsed['type'] === '' || $currentDueAt === '') {
        return null;
    }

    try {
        $currentDateTime = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $currentDueAt);
        if (!$currentDateTime instanceof DateTimeImmutable) {
            $currentDateTime = new DateTimeImmutable($currentDueAt);
        }

        $nextDateTime = match ($parsed['type']) {
            'daily' => $currentDateTime->modify('+' . $parsed['interval'] . ' day'),
            'weekly' => $currentDateTime->modify('+' . $parsed['interval'] . ' week'),
            'monthly' => $currentDateTime->modify('+' . $parsed['interval'] . ' month'),
            'yearly' => $currentDateTime->modify('+' . $parsed['interval'] . ' year'),
            default => null,
        };

        if ($nextDateTime === null) {
            return null;
        }

        if ($parsed['end_date'] !== '') {
            $endDateTime = parseRepeatEndDate($parsed['end_date']);
            if (!$endDateTime instanceof DateTimeImmutable) {
                writeDebugLog('calculate_next_due_at_error', [
                    'current_due_at' => $currentDueAt,
                    'repeat_rule' => $repeatRule,
                    'end_date' => $parsed['end_date'],
                ], 'failed', [
                    'reason' => 'stored_end_date_unparseable',
                ]);
                return null;
            }
            if ($nextDateTime > $endDateTime) {
                return null;
            }
        }

        return $nextDateTime->format('Y-m-d H:i:s');
    } catch (Throwable $exception) {
        writeDebugLog('calculate_next_due_at_error', [
            'current_due_at' => $currentDueAt,
            'repeat_rule' => $repeatRule,
            'error' => $exception->getMessage(),
        ], 'failed', [
            'reason' => 'calculation_exception',
        ]);
        return null;
    }
}

function createRecurrenceId(): string
{
    try {
        return 'recurrence-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
    } catch (Throwable $exception) {
        return 'recurrence-' . date('YmdHis') . '-' . str_replace('.', '', uniqid('', true));
    }
}

function setLastRecurrenceGenerationResult(string $status, array $context = []): void
{
    $GLOBALS['lastRecurrenceGenerationResult'] = [
        'status' => $status,
        'context' => $context,
    ];
}

function getLastRecurrenceGenerationResult(): array
{
    return isset($GLOBALS['lastRecurrenceGenerationResult']) && is_array($GLOBALS['lastRecurrenceGenerationResult'])
        ? $GLOBALS['lastRecurrenceGenerationResult']
        : ['status' => 'not_applicable', 'context' => []];
}

function buildStatusChangeRedirectUrl(): string
{
    $result = getLastRecurrenceGenerationResult();
    $status = isset($result['status']) && is_string($result['status']) ? $result['status'] : 'not_applicable';
    $query = ['status_changed' => '1'];

    if ($status === 'created') {
        $query['recurrence_created'] = '1';
    } elseif ($status === 'skipped') {
        $query['recurrence_skipped'] = '1';
    } elseif ($status === 'duplicate') {
        $query['recurrence_duplicate'] = '1';
    } elseif ($status === 'failed') {
        $query['recurrence_failed'] = '1';
    }

    return 'index.php?' . http_build_query($query);
}

function saveTaskRecurrence(string $sourceTaskId, string $generatedTaskId, string $repeatRule, string $sourceDueAt, string $generatedDueAt): bool
{
    try {
        $pdo = getDatabaseConnection();
        $now = date('Y-m-d H:i:s');
        $recurrenceId = createRecurrenceId();

        $statement = $pdo->prepare(
            "INSERT INTO task_recurrences
            (id, source_task_id, generated_task_id, repeat_rule, source_due_at, generated_due_at, created_at)
            VALUES
            (:id, :source_task_id, :generated_task_id, :repeat_rule, :source_due_at, :generated_due_at, :created_at)"
        );

        $statement->execute([
            ':id' => $recurrenceId,
            ':source_task_id' => $sourceTaskId,
            ':generated_task_id' => $generatedTaskId,
            ':repeat_rule' => $repeatRule,
            ':source_due_at' => $sourceDueAt,
            ':generated_due_at' => $generatedDueAt,
            ':created_at' => $now,
        ]);
        $statement->closeCursor();

        writeDebugLog('repeat_rule_save', [
            'source_task_id' => $sourceTaskId,
            'generated_task_id' => $generatedTaskId,
            'recurrence_id' => $recurrenceId,
            'repeat_rule' => $repeatRule,
            'source_due_at' => $sourceDueAt,
            'generated_due_at' => $generatedDueAt,
        ], 'success', [
            'database_file' => basename(DB_FILE),
            'created_at' => $now,
        ]);

        return true;
    } catch (Throwable $exception) {
        writeDebugLog('repeat_rule_save_exception', [
            'source_task_id' => $sourceTaskId,
            'generated_task_id' => $generatedTaskId,
            'repeat_rule' => $repeatRule,
            'source_due_at' => $sourceDueAt,
            'generated_due_at' => $generatedDueAt,
        ], 'failed', [
            'reason' => 'database_write_exception',
            'message' => $exception->getMessage(),
            'database_file' => basename(DB_FILE),
        ]);
        return false;
    }
}

function loadTaskRecurrences(string $taskId): array
{
    try {
        $pdo = getDatabaseConnection();
        $statement = $pdo->prepare(
            "SELECT * FROM task_recurrences WHERE source_task_id = :task_id OR generated_task_id = :task_id ORDER BY created_at DESC"
        );
        $statement->execute([':task_id' => $taskId]);
        $rows = $statement->fetchAll();
        $statement->closeCursor();

        $recurrences = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $recurrences[] = [
                'id' => isset($row['id']) && is_string($row['id']) ? trim($row['id']) : '',
                'source_task_id' => isset($row['source_task_id']) && is_string($row['source_task_id']) ? trim($row['source_task_id']) : '',
                'generated_task_id' => isset($row['generated_task_id']) && is_string($row['generated_task_id']) ? trim($row['generated_task_id']) : '',
                'repeat_rule' => isset($row['repeat_rule']) && is_string($row['repeat_rule']) ? trim($row['repeat_rule']) : '',
                'source_due_at' => isset($row['source_due_at']) && is_string($row['source_due_at']) ? trim($row['source_due_at']) : '',
                'generated_due_at' => isset($row['generated_due_at']) && is_string($row['generated_due_at']) ? trim($row['generated_due_at']) : '',
                'created_at' => isset($row['created_at']) && is_string($row['created_at']) ? trim($row['created_at']) : '',
            ];
        }

        writeDebugLog('repeat_rule_load', [
            'task_id' => $taskId,
        ], 'success', [
            'recurrence_count' => count($recurrences),
        ]);

        return $recurrences;
    } catch (Throwable $exception) {
        writeDebugLog('repeat_rule_load_exception', [
            'task_id' => $taskId,
        ], 'failed', [
            'reason' => 'database_read_exception',
            'message' => $exception->getMessage(),
            'database_file' => basename(DB_FILE),
        ]);
        return [];
    }
}

function generateNextRecurrence(string $sourceTaskId): ?array
{
    try {
        $pdo = getDatabaseConnection();
        $statement = $pdo->prepare('SELECT * FROM tasks WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $statement->execute([':id' => $sourceTaskId]);
        $row = $statement->fetch();
        $statement->closeCursor();

        if (!is_array($row)) {
            writeDebugLog('repeat_generation_source_not_found', [
                'source_task_id' => $sourceTaskId,
            ], 'failed', [
                'reason' => 'source_task_not_found',
            ]);
            return null;
        }

        $repeatRule = isset($row['repeat_rule']) && is_string($row['repeat_rule']) ? trim($row['repeat_rule']) : '';
        if ($repeatRule === '') {
            writeDebugLog('repeat_generation_no_rule', [
                'source_task_id' => $sourceTaskId,
            ], 'failed', [
                'reason' => 'no_repeat_rule_defined',
            ]);
            return null;
        }

        $dueAt = isset($row['due_at']) && is_string($row['due_at']) ? trim($row['due_at']) : '';
        if ($dueAt === '') {
            writeDebugLog('repeat_generation_no_due_at', [
                'source_task_id' => $sourceTaskId,
            ], 'failed', [
                'reason' => 'no_due_at_for_calculation',
            ]);
            return null;
        }

        $existingRecurrenceStatement = $pdo->prepare(
            'SELECT generated_task_id
            FROM task_recurrences
            WHERE source_task_id = :source_task_id
                AND source_due_at = :source_due_at
                AND repeat_rule = :repeat_rule
            LIMIT 1'
        );
        $existingRecurrenceStatement->execute([
            ':source_task_id' => $sourceTaskId,
            ':source_due_at' => $dueAt,
            ':repeat_rule' => $repeatRule,
        ]);
        $existingGeneratedTaskId = $existingRecurrenceStatement->fetchColumn();
        $existingRecurrenceStatement->closeCursor();
        if (is_string($existingGeneratedTaskId) && $existingGeneratedTaskId !== '') {
            writeDebugLog('repeat_generation_duplicate_blocked', [
                'source_task_id' => $sourceTaskId,
                'existing_generated_task_id' => $existingGeneratedTaskId,
                'repeat_rule' => $repeatRule,
                'source_due_at' => $dueAt,
            ], 'failed', [
                'reason' => 'next_occurrence_already_generated',
                'database_write_blocked' => true,
            ]);
            return null;
        }

        $nextDueAt = calculateNextDueAt($dueAt, $repeatRule);
        if ($nextDueAt === null) {
            writeDebugLog('repeat_generation_no_more_occurrences', [
                'source_task_id' => $sourceTaskId,
                'repeat_rule' => $repeatRule,
                'current_due_at' => $dueAt,
            ], 'failed', [
                'reason' => 'end_date_reached_or_calculation_failed',
            ]);
            return null;
        }

        $newTaskId = createTaskId();
        $now = date('Y-m-d H:i:s');
        $insertStatement = $pdo->prepare(
            "INSERT INTO tasks
            (id, title, content, status, priority, category_id, due_at, repeat_rule, created_at, updated_at)
            VALUES
            (:id, :title, :content, :status, :priority, :category_id, :due_at, :repeat_rule, :created_at, :updated_at)"
        );

        $title = isset($row['title']) && is_string($row['title']) ? trim($row['title']) : '';
        $content = isset($row['content']) && is_string($row['content']) ? trim($row['content']) : '';
        $priority = isset($row['priority']) && is_string($row['priority']) ? trim($row['priority']) : DEFAULT_TASK_PRIORITY;
        $categoryId = isset($row['category_id']) && is_string($row['category_id']) ? trim($row['category_id']) : null;
        if ($categoryId === '') {
            $categoryId = null;
        }

        $insertStatement->execute([
            ':id' => $newTaskId,
            ':title' => $title,
            ':content' => $content,
            ':status' => '未开始',
            ':priority' => $priority,
            ':category_id' => $categoryId,
            ':due_at' => $nextDueAt,
            ':repeat_rule' => $repeatRule,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $insertStatement->closeCursor();

        $recurrenceSaved = saveTaskRecurrence($sourceTaskId, $newTaskId, $repeatRule, $dueAt, $nextDueAt);
        if (!$recurrenceSaved) {
            $rollbackStatement = $pdo->prepare('DELETE FROM tasks WHERE id = :id');
            $rollbackStatement->execute([':id' => $newTaskId]);
            $rollbackStatement->closeCursor();
            writeDebugLog('repeat_generation_exception', [
                'source_task_id' => $sourceTaskId,
                'generated_task_id' => $newTaskId,
                'repeat_rule' => $repeatRule,
                'source_due_at' => $dueAt,
                'generated_due_at' => $nextDueAt,
            ], 'failed', [
                'reason' => 'recurrence_record_write_failed',
                'generated_task_rolled_back' => true,
            ]);
            return null;
        }

        writeDebugLog('repeat_generation_success', [
            'source_task_id' => $sourceTaskId,
            'generated_task_id' => $newTaskId,
            'repeat_rule' => $repeatRule,
            'source_due_at' => $dueAt,
            'generated_due_at' => $nextDueAt,
            'recurrence_recorded' => $recurrenceSaved,
        ], 'success', [
            'database_file' => basename(DB_FILE),
        ]);

        $historyWritten = recordTaskHistory($newTaskId, 'create', buildTaskFieldChanges([], [
            'title' => $title,
            'content' => $content,
            'status' => '未开始',
            'priority' => $priority,
            'category_id' => $categoryId ?? '',
            'due_at' => $nextDueAt,
            'repeat_rule' => $repeatRule,
            'deleted_at' => '',
        ], ['title', 'content', 'status', 'priority', 'category_id', 'due_at', 'repeat_rule', 'deleted_at']), 'success', [
            'source' => 'recurrence_generation',
            'source_task_id' => $sourceTaskId,
            'source_due_at' => $dueAt,
            'generated_due_at' => $nextDueAt,
            'created_at' => $now,
        ]);
        if (!$historyWritten) {
            writeDebugLog('repeat_generation_history_warning', [
                'source_task_id' => $sourceTaskId,
                'generated_task_id' => $newTaskId,
                'operation_type' => 'create',
            ], 'failed', [
                'reason' => 'history_write_failed_after_recurrence_generation',
                'message' => getLastTaskHistoryError(),
                'main_operation_kept' => true,
            ]);
        }

        return [
            'id' => $newTaskId,
            'title' => $title,
            'content' => $content,
            'status' => '未开始',
            'priority' => $priority,
            'category_id' => $categoryId,
            'due_at' => $nextDueAt,
            'repeat_rule' => $repeatRule,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    } catch (Throwable $exception) {
        writeDebugLog('repeat_generation_exception', [
            'source_task_id' => $sourceTaskId,
        ], 'failed', [
            'reason' => 'database_exception',
            'message' => $exception->getMessage(),
            'database_file' => basename(DB_FILE),
        ]);
        return null;
    }
}

function buildRepeatState(array $task, string $source): array
{
    $taskId = isset($task['id']) && is_string($task['id']) ? $task['id'] : '';
    $repeatRule = isset($task['repeat_rule']) && is_string($task['repeat_rule']) ? trim($task['repeat_rule']) : '';
    $nowTimestamp = time();

    if ($repeatRule === '') {
        writeDebugLog('task_repeat_status_calculate', [
            'task_id' => $taskId,
            'repeat_rule' => '',
            'source' => $source,
        ], 'success', [
            'result_status' => '未设置',
            'now' => date('Y-m-d H:i:s', $nowTimestamp),
            'has_repeat_rule' => false,
        ]);
        return [
            'label' => '未设置',
            'class' => 'repeat-none',
            'description' => '未设置重复规则',
            'rule' => '',
            'type' => '',
            'interval' => 0,
            'end_date' => '',
        ];
    }

    $parsed = parseRepeatRule($repeatRule);
    $typeLabel = REPEAT_TYPE_LABELS[$parsed['type']] ?? $parsed['type'];
    $repeatUnits = [
        'daily' => '天',
        'weekly' => '周',
        'monthly' => '月',
        'yearly' => '年',
    ];
    $unitLabel = $repeatUnits[$parsed['type']] ?? $typeLabel;
    $fullLabel = $parsed['interval'] > 1 ? '每 ' . $parsed['interval'] . ' ' . $unitLabel : $typeLabel;

    $description = $fullLabel;
    if ($parsed['end_date'] !== '') {
        $description .= '，至 ' . $parsed['end_date'];
    }

    writeDebugLog('task_repeat_status_calculate', [
        'task_id' => $taskId,
        'repeat_rule' => $repeatRule,
        'source' => $source,
    ], 'success', [
        'result_status' => '已设置',
        'now' => date('Y-m-d H:i:s', $nowTimestamp),
        'has_repeat_rule' => true,
        'type' => $parsed['type'],
        'interval' => $parsed['interval'],
        'end_date' => $parsed['end_date'],
    ]);

    return [
        'label' => $fullLabel,
        'class' => 'repeat-active',
        'description' => $description,
        'rule' => $repeatRule,
        'type' => $parsed['type'],
        'interval' => $parsed['interval'],
        'end_date' => $parsed['end_date'],
    ];
}

function buildRemindState(array $task, string $source): array
{
    $taskId = isset($task['id']) && is_string($task['id']) ? $task['id'] : '';
    $taskStatus = isset($task['status']) && is_string($task['status']) ? trim($task['status']) : '未开始';
    $remindAt = normalizeStoredRemindAt($task['remind_at'] ?? '');
    $nowTimestamp = time();

    if ($remindAt === '') {
        writeDebugLog('task_remind_status_calculate', [
            'task_id' => $taskId,
            'remind_at' => '',
            'source' => $source,
        ], 'success', [
            'result_status' => '未设置',
            'now' => date('Y-m-d H:i:s', $nowTimestamp),
            'has_remind_at' => false,
        ]);
        return [
            'label' => '未设置',
            'class' => 'remind-none',
            'description' => '未设置提醒时间',
            'datetime' => '',
            'is_triggered' => false,
        ];
    }

    $remindTimestamp = strtotime($remindAt);
    if ($remindTimestamp === false) {
        writeDebugLog('task_remind_status_calculate', [
            'task_id' => $taskId,
            'remind_at' => $remindAt,
            'source' => $source,
        ], 'failed', [
            'reason' => 'stored_remind_at_unparseable',
            'result_status' => '无效日期',
            'now' => date('Y-m-d H:i:s', $nowTimestamp),
        ]);
        return [
            'label' => '无效日期',
            'class' => 'remind-invalid',
            'description' => '提醒时间格式异常',
            'datetime' => $remindAt,
            'is_triggered' => false,
        ];
    }

    $isCompleted = $taskStatus === '已完成' || $taskStatus === '已归档';
    if ($isCompleted) {
        writeDebugLog('task_remind_status_calculate', [
            'task_id' => $taskId,
            'remind_at' => $remindAt,
            'source' => $source,
        ], 'success', [
            'result_status' => '已完成不提醒',
            'now' => date('Y-m-d H:i:s', $nowTimestamp),
            'has_remind_at' => true,
            'task_status' => $taskStatus,
            'is_completed' => true,
        ]);
        return [
            'label' => '已完成',
            'class' => 'remind-completed',
            'description' => '完成任务不触发提醒',
            'datetime' => $remindAt,
            'is_triggered' => false,
        ];
    }

    if ($remindTimestamp <= $nowTimestamp) {
        writeDebugLog('task_remind_status_calculate', [
            'task_id' => $taskId,
            'remind_at' => $remindAt,
            'source' => $source,
        ], 'success', [
            'result_status' => '提醒中',
            'now' => date('Y-m-d H:i:s', $nowTimestamp),
            'has_remind_at' => true,
            'task_status' => $taskStatus,
            'is_triggered' => true,
        ]);
        return [
            'label' => '提醒中',
            'class' => 'remind-active',
            'description' => '提醒时间已到',
            'datetime' => $remindAt,
            'is_triggered' => true,
        ];
    }

    writeDebugLog('task_remind_status_calculate', [
        'task_id' => $taskId,
        'remind_at' => $remindAt,
        'source' => $source,
    ], 'success', [
        'result_status' => '待提醒',
        'now' => date('Y-m-d H:i:s', $nowTimestamp),
        'has_remind_at' => true,
        'task_status' => $taskStatus,
        'is_triggered' => false,
    ]);

    return [
        'label' => '待提醒',
        'class' => 'remind-pending',
        'description' => '提醒时间：' . formatDateTime($remindAt),
        'datetime' => $remindAt,
        'is_triggered' => false,
    ];
}

function buildDueAtState(array $task, string $source): array
{
    $taskId = isset($task['id']) && is_string($task['id']) ? $task['id'] : '';
    $dueAt = normalizeStoredDueAt($task['due_at'] ?? '');
    $nowTimestamp = time();
    $todayStart = strtotime(date('Y-m-d 00:00:00', $nowTimestamp));
    $todayEnd = strtotime(date('Y-m-d 23:59:59', $nowTimestamp));

    if ($dueAt === '') {
        writeDebugLog('task_due_at_status_calculate', [
            'task_id' => $taskId,
            'due_at' => '',
            'source' => $source,
        ], 'success', [
            'result_status' => '未设置',
            'now' => date('Y-m-d H:i:s', $nowTimestamp),
            'has_due_at' => false,
        ]);
        return [
            'label' => '未设置',
            'class' => 'due-none',
            'description' => '未设置截止时间',
            'datetime' => '',
        ];
    }

    $dueTimestamp = strtotime($dueAt);
    if ($dueTimestamp === false) {
        writeDebugLog('task_due_at_status_calculate', [
            'task_id' => $taskId,
            'due_at' => $dueAt,
            'source' => $source,
        ], 'failed', [
            'reason' => 'stored_due_at_unparseable',
            'result_status' => '无效日期',
            'now' => date('Y-m-d H:i:s', $nowTimestamp),
        ]);
        return [
            'label' => '无效日期',
            'class' => 'due-invalid',
            'description' => '截止时间格式异常',
            'datetime' => $dueAt,
        ];
    }

    if ($dueTimestamp < $nowTimestamp) {
        $label = '已逾期';
        $class = 'due-overdue';
    } elseif ($todayStart !== false && $todayEnd !== false && $dueTimestamp >= $todayStart && $dueTimestamp <= $todayEnd) {
        $label = '今日到期';
        $class = 'due-today';
    } else {
        $label = '未到期';
        $class = 'due-future';
    }

    writeDebugLog('task_due_at_status_calculate', [
        'task_id' => $taskId,
        'due_at' => $dueAt,
        'source' => $source,
    ], 'success', [
        'result_status' => $label,
        'now' => date('Y-m-d H:i:s', $nowTimestamp),
        'due_timestamp' => $dueTimestamp,
        'has_due_at' => true,
    ]);

    return [
        'label' => $label,
        'class' => $class,
        'description' => formatDateTime($dueAt),
        'datetime' => $dueAt,
    ];
}

function normalizeTask(array $task, int $index): array
{
    $createdAt = isset($task['created_at']) && is_string($task['created_at']) && trim($task['created_at']) !== ''
        ? trim($task['created_at'])
        : '1970-01-01 00:00:00';
    $updatedAt = isset($task['updated_at']) && is_string($task['updated_at']) && trim($task['updated_at']) !== ''
        ? trim($task['updated_at'])
        : $createdAt;

    $rawStatus = isset($task['status']) && is_string($task['status']) && trim($task['status']) !== ''
        ? trim($task['status'])
        : '未开始';
    $rawPriority = isset($task['priority']) && is_string($task['priority']) && trim($task['priority']) !== ''
        ? trim($task['priority'])
        : DEFAULT_TASK_PRIORITY;

    return [
        'id' => isset($task['id']) && is_string($task['id']) && trim($task['id']) !== ''
            ? trim($task['id'])
            : 'task-' . $index,
        'title' => isset($task['title']) && is_string($task['title'])
            ? $task['title']
            : '未命名任务',
        'content' => isset($task['content']) && is_string($task['content'])
            ? $task['content']
            : '',
        'status' => normalizeTaskStatus($rawStatus),
        'priority' => normalizeTaskPriority($rawPriority),
        'category_id' => isset($task['category_id']) && is_string($task['category_id'])
            ? trim($task['category_id'])
            : '',
        'category_name' => isset($task['category_name']) && is_string($task['category_name'])
            ? trim($task['category_name'])
            : '',
        'due_at' => normalizeStoredDueAt($task['due_at'] ?? ''),
        'remind_at' => normalizeStoredRemindAt($task['remind_at'] ?? ''),
        'repeat_rule' => isset($task['repeat_rule']) && is_string($task['repeat_rule'])
            ? trim($task['repeat_rule'])
            : '',
        'created_at' => $createdAt,
        'updated_at' => $updatedAt,
        'archived_at' => isset($task['archived_at']) && is_string($task['archived_at'])
            ? trim($task['archived_at'])
            : '',
        'archive_previous_status' => isset($task['archive_previous_status']) && is_string($task['archive_previous_status'])
            ? normalizeTaskStatus($task['archive_previous_status'])
            : '',
        'deleted_at' => isset($task['deleted_at']) && is_string($task['deleted_at'])
            ? trim($task['deleted_at'])
            : '',
    ];
}

function stringLength(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }

    return strlen($value);
}

function normalizeSearchText(string $value): string
{
    $normalized = preg_replace('/\s+/u', ' ', trim($value));
    if (!is_string($normalized)) {
        $normalized = trim($value);
    }

    return function_exists('mb_strtolower') ? mb_strtolower($normalized, 'UTF-8') : strtolower($normalized);
}

function stringContainsInsensitive(string $haystack, string $needle): bool
{
    if ($needle === '') {
        return true;
    }

    $normalizedHaystack = normalizeSearchText($haystack);
    $normalizedNeedle = normalizeSearchText($needle);

    if (function_exists('mb_strpos')) {
        return mb_strpos($normalizedHaystack, $normalizedNeedle, 0, 'UTF-8') !== false;
    }

    return strpos($normalizedHaystack, $normalizedNeedle) !== false;
}

function setDatabaseErrorMessage(string $message): void
{
    $GLOBALS['databaseErrorMessage'] = $message;
}

function getDatabaseErrorMessage(): string
{
    return isset($GLOBALS['databaseErrorMessage']) && is_string($GLOBALS['databaseErrorMessage'])
        ? $GLOBALS['databaseErrorMessage']
        : '';
}

function createDatabaseConnection(): PDO
{
    if (!extension_loaded('pdo_sqlite')) {
        writeDebugLog('database_connection_exception', [
            'driver' => 'pdo_sqlite',
        ], 'failed', [
            'reason' => 'pdo_sqlite_extension_missing',
        ]);
        throw new RuntimeException('当前 PHP 环境未启用 SQLite 数据库扩展。');
    }

    $dataDirectory = dirname(DB_FILE);
    if (!is_dir($dataDirectory) && !mkdir($dataDirectory, 0775, true) && !is_dir($dataDirectory)) {
        writeDebugLog('database_connection_exception', [
            'database_file' => basename(DB_FILE),
        ], 'failed', [
            'reason' => 'data_directory_unavailable',
            'directory' => $dataDirectory,
        ]);
        throw new RuntimeException('数据库目录不可写，无法初始化数据存储。');
    }

    $databaseExisted = file_exists(DB_FILE);
    writeDebugLog('database_connection_open', [
        'database_file' => basename(DB_FILE),
        'database_existed' => $databaseExisted,
    ], 'started');

    $pdo = new PDO('sqlite:' . DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');

    writeDebugLog('database_connection_open', [
        'database_file' => basename(DB_FILE),
        'database_existed' => $databaseExisted,
    ], 'success');

    return $pdo;
}

function getDatabaseConnection(): PDO
{
    static $pdo = null;
    static $schemaReady = false;

    if (!$pdo instanceof PDO) {
        $pdo = createDatabaseConnection();
    }

    if (!$schemaReady) {
        initializeDatabaseSchema($pdo);
        ensureTaskCategorySchema($pdo);
        ensureTaskDeadlineSchema($pdo);
        ensureTaskArchiveSchema($pdo);
        ensureTaskPrioritySchema($pdo);
        ensureTaskRecurrenceSchema($pdo);
        ensureTaskHistorySchema($pdo);
        migrateLegacyTasksJson($pdo);
        migrateTaskStatuses($pdo);
        migrateTaskPriorities($pdo);
        $schemaReady = true;
    }

    return $pdo;
}

function initializeDatabaseSchema(PDO $pdo): void
{
    writeDebugLog('database_schema_initialize', [
        'database_file' => basename(DB_FILE),
    ], 'started');

    try {
        $GLOBALS['suppressDatabaseDebugLog'] = true;
        $pdo->beginTransaction();

        $statements = [
            'categories' => "CREATE TABLE IF NOT EXISTS categories (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL UNIQUE,
                color TEXT NOT NULL DEFAULT '#2563eb',
                sort_order INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
            )",
            'tasks' => "CREATE TABLE IF NOT EXISTS tasks (
                id TEXT PRIMARY KEY,
                title TEXT NOT NULL,
                content TEXT NOT NULL DEFAULT '',
                status TEXT NOT NULL DEFAULT '待处理',
                priority TEXT NOT NULL DEFAULT '中',
                category_id TEXT NULL,
                due_at TEXT NULL,
                repeat_rule TEXT NULL,
                archived_at TEXT NULL,
                archive_previous_status TEXT NULL,
                deleted_at TEXT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
                FOREIGN KEY (category_id) REFERENCES categories(id) ON UPDATE CASCADE ON DELETE SET NULL
            )",
            'tags' => "CREATE TABLE IF NOT EXISTS tags (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL UNIQUE,
                color TEXT NOT NULL DEFAULT '#667085',
                created_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
            )",
            'task_tags' => "CREATE TABLE IF NOT EXISTS task_tags (
                task_id TEXT NOT NULL,
                tag_id TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
                PRIMARY KEY (task_id, tag_id),
                FOREIGN KEY (task_id) REFERENCES tasks(id) ON UPDATE CASCADE ON DELETE CASCADE,
                FOREIGN KEY (tag_id) REFERENCES tags(id) ON UPDATE CASCADE ON DELETE CASCADE
            )",
            'subtasks' => "CREATE TABLE IF NOT EXISTS subtasks (
                id TEXT PRIMARY KEY,
                task_id TEXT NOT NULL,
                title TEXT NOT NULL,
                is_completed INTEGER NOT NULL DEFAULT 0,
                sort_order INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
                FOREIGN KEY (task_id) REFERENCES tasks(id) ON UPDATE CASCADE ON DELETE CASCADE
            )",
            'comments' => "CREATE TABLE IF NOT EXISTS comments (
                id TEXT PRIMARY KEY,
                task_id TEXT NOT NULL,
                content TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
                FOREIGN KEY (task_id) REFERENCES tasks(id) ON UPDATE CASCADE ON DELETE CASCADE
            )",
            'attachments' => "CREATE TABLE IF NOT EXISTS attachments (
                id TEXT PRIMARY KEY,
                task_id TEXT NOT NULL,
                file_name TEXT NOT NULL,
                file_size INTEGER NOT NULL DEFAULT 0,
                mime_type TEXT NOT NULL DEFAULT 'application/octet-stream',
                storage_path TEXT NOT NULL DEFAULT '',
                created_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
                FOREIGN KEY (task_id) REFERENCES tasks(id) ON UPDATE CASCADE ON DELETE CASCADE
            )",
            'operation_logs' => "CREATE TABLE IF NOT EXISTS operation_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                operation TEXT NOT NULL,
                parameters_json TEXT NOT NULL DEFAULT '{}',
                status TEXT NOT NULL,
                context_json TEXT NOT NULL DEFAULT '{}',
                created_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
            )",
            'reminders' => "CREATE TABLE IF NOT EXISTS reminders (
                id TEXT PRIMARY KEY,
                task_id TEXT NOT NULL,
                remind_at TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'pending',
                delivered_at TEXT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
                FOREIGN KEY (task_id) REFERENCES tasks(id) ON UPDATE CASCADE ON DELETE CASCADE
            )",
            'system_settings' => "CREATE TABLE IF NOT EXISTS system_settings (
                setting_key TEXT PRIMARY KEY,
                setting_value TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
            )",
            'task_recurrences' => "CREATE TABLE IF NOT EXISTS task_recurrences (
                id TEXT PRIMARY KEY,
                source_task_id TEXT NOT NULL,
                generated_task_id TEXT NOT NULL,
                repeat_rule TEXT NOT NULL,
                source_due_at TEXT NOT NULL,
                generated_due_at TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
                FOREIGN KEY (source_task_id) REFERENCES tasks(id) ON UPDATE CASCADE ON DELETE CASCADE,
                FOREIGN KEY (generated_task_id) REFERENCES tasks(id) ON UPDATE CASCADE ON DELETE CASCADE
            )",
            'task_histories' => "CREATE TABLE IF NOT EXISTS task_histories (
                id TEXT PRIMARY KEY,
                task_id TEXT NOT NULL,
                operation_type TEXT NOT NULL,
                field_changes_json TEXT NOT NULL DEFAULT '{}',
                result_status TEXT NOT NULL,
                result_json TEXT NOT NULL DEFAULT '{}',
                created_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
            )",
        ];

        foreach ($statements as $tableName => $sql) {
            writeDebugLog('database_table_create', [
                'table' => $tableName,
                'database_file' => basename(DB_FILE),
            ], 'started');
            $pdo->exec($sql);
            writeDebugLog('database_table_create', [
                'table' => $tableName,
                'database_file' => basename(DB_FILE),
            ], 'success');
        }

        $indexes = [
            'idx_tasks_status' => 'CREATE INDEX IF NOT EXISTS idx_tasks_status ON tasks(status)',
            'idx_tasks_archived_at' => 'CREATE INDEX IF NOT EXISTS idx_tasks_archived_at ON tasks(archived_at)',
            'idx_tasks_deleted_at' => 'CREATE INDEX IF NOT EXISTS idx_tasks_deleted_at ON tasks(deleted_at)',
            'idx_tasks_created_at' => 'CREATE INDEX IF NOT EXISTS idx_tasks_created_at ON tasks(created_at)',
            'idx_tasks_updated_at' => 'CREATE INDEX IF NOT EXISTS idx_tasks_updated_at ON tasks(updated_at)',
            'idx_tasks_category_id' => 'CREATE INDEX IF NOT EXISTS idx_tasks_category_id ON tasks(category_id)',
            'idx_task_tags_tag_id' => 'CREATE INDEX IF NOT EXISTS idx_task_tags_tag_id ON task_tags(tag_id)',
            'idx_subtasks_task_id' => 'CREATE INDEX IF NOT EXISTS idx_subtasks_task_id ON subtasks(task_id)',
            'idx_comments_task_id' => 'CREATE INDEX IF NOT EXISTS idx_comments_task_id ON comments(task_id)',
            'idx_attachments_task_id' => 'CREATE INDEX IF NOT EXISTS idx_attachments_task_id ON attachments(task_id)',
            'idx_reminders_task_id' => 'CREATE INDEX IF NOT EXISTS idx_reminders_task_id ON reminders(task_id)',
            'idx_reminders_remind_at' => 'CREATE INDEX IF NOT EXISTS idx_reminders_remind_at ON reminders(remind_at)',
            'idx_task_histories_task_id' => 'CREATE INDEX IF NOT EXISTS idx_task_histories_task_id ON task_histories(task_id)',
            'idx_task_histories_created_at' => 'CREATE INDEX IF NOT EXISTS idx_task_histories_created_at ON task_histories(created_at)',
        ];

        foreach ($indexes as $indexName => $sql) {
            $pdo->exec($sql);
            writeDebugLog('database_index_create', [
                'index' => $indexName,
                'database_file' => basename(DB_FILE),
            ], 'success');
        }

        upsertSystemSetting($pdo, 'schema_version', '1');
        $pdo->commit();
        $GLOBALS['suppressDatabaseDebugLog'] = false;

        writeDebugLog('database_schema_initialize', [
            'database_file' => basename(DB_FILE),
            'table_count' => count($statements),
            'index_count' => count($indexes),
        ], 'success');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $GLOBALS['suppressDatabaseDebugLog'] = false;

        writeDebugLog('database_schema_initialize', [
            'database_file' => basename(DB_FILE),
        ], 'failed', [
            'reason' => 'schema_exception',
            'message' => $exception->getMessage(),
        ]);
        throw $exception;
    }
}

function ensureTaskCategorySchema(PDO $pdo): void
{
    writeDebugLog('task_category_schema_check', [
        'database_file' => basename(DB_FILE),
        'column' => 'category_id',
    ], 'started');

    try {
        $columnsStatement = $pdo->query('PRAGMA table_info(tasks)');
        $columns = $columnsStatement !== false ? $columnsStatement->fetchAll() : [];
        $hasCategoryColumn = false;

        foreach ($columns as $column) {
            if (is_array($column) && isset($column['name']) && (string) $column['name'] === 'category_id') {
                $hasCategoryColumn = true;
                break;
            }
        }

        if (!$hasCategoryColumn) {
            $pdo->exec('ALTER TABLE tasks ADD COLUMN category_id TEXT NULL');
            writeDebugLog('task_category_schema_alter', [
                'database_file' => basename(DB_FILE),
                'column' => 'category_id',
            ], 'success');
        }

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_category_id ON tasks(category_id)');
        writeDebugLog('task_category_schema_check', [
            'database_file' => basename(DB_FILE),
            'column' => 'category_id',
            'index' => 'idx_tasks_category_id',
        ], 'success', [
            'column_added' => !$hasCategoryColumn,
            'reference_policy' => 'delete_blocked_when_referenced',
        ]);
    } catch (Throwable $exception) {
        writeDebugLog('task_category_schema_check', [
            'database_file' => basename(DB_FILE),
            'column' => 'category_id',
        ], 'failed', [
            'reason' => 'category_schema_exception',
            'message' => $exception->getMessage(),
        ]);
        throw $exception;
    }
}

function ensureTaskDeadlineSchema(PDO $pdo): void
{
    writeDebugLog('task_due_at_schema_check', [
        'database_file' => basename(DB_FILE),
        'column' => 'due_at',
    ], 'started');

    try {
        $columnsStatement = $pdo->query('PRAGMA table_info(tasks)');
        $columns = $columnsStatement !== false ? $columnsStatement->fetchAll() : [];
        $hasDueAtColumn = false;

        foreach ($columns as $column) {
            if (is_array($column) && isset($column['name']) && (string) $column['name'] === 'due_at') {
                $hasDueAtColumn = true;
                break;
            }
        }

        if (!$hasDueAtColumn) {
            $pdo->exec('ALTER TABLE tasks ADD COLUMN due_at TEXT NULL');
            writeDebugLog('task_due_at_schema_alter', [
                'database_file' => basename(DB_FILE),
                'column' => 'due_at',
            ], 'success');
        }

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_due_at ON tasks(due_at)');
        writeDebugLog('task_due_at_schema_check', [
            'database_file' => basename(DB_FILE),
            'column' => 'due_at',
            'index' => 'idx_tasks_due_at',
        ], 'success', [
            'column_added' => !$hasDueAtColumn,
            'min_due_at' => MIN_DUE_AT,
            'max_due_at' => MAX_DUE_AT,
        ]);
    } catch (Throwable $exception) {
        writeDebugLog('task_due_at_schema_check', [
            'database_file' => basename(DB_FILE),
            'column' => 'due_at',
        ], 'failed', [
            'reason' => 'due_at_schema_exception',
            'message' => $exception->getMessage(),
        ]);
        throw $exception;
    }
}

function ensureTaskArchiveSchema(PDO $pdo): void
{
    writeDebugLog('task_archive_schema_check', [
        'database_file' => basename(DB_FILE),
        'columns' => ['archived_at', 'archive_previous_status'],
    ], 'started');

    try {
        $columnsStatement = $pdo->query('PRAGMA table_info(tasks)');
        $columns = $columnsStatement !== false ? $columnsStatement->fetchAll() : [];
        $columnNames = [];

        foreach ($columns as $column) {
            if (is_array($column) && isset($column['name']) && is_string($column['name'])) {
                $columnNames[$column['name']] = true;
            }
        }

        $addedColumns = [];
        if (!isset($columnNames['archived_at'])) {
            $pdo->exec('ALTER TABLE tasks ADD COLUMN archived_at TEXT NULL');
            $addedColumns[] = 'archived_at';
        }
        if (!isset($columnNames['archive_previous_status'])) {
            $pdo->exec('ALTER TABLE tasks ADD COLUMN archive_previous_status TEXT NULL');
            $addedColumns[] = 'archive_previous_status';
        }

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_archived_at ON tasks(archived_at)');
        writeDebugLog('task_archive_schema_check', [
            'database_file' => basename(DB_FILE),
            'columns' => ['archived_at', 'archive_previous_status'],
            'index' => 'idx_tasks_archived_at',
        ], 'success', [
            'added_columns' => $addedColumns,
        ]);
    } catch (Throwable $exception) {
        writeDebugLog('task_archive_schema_check', [
            'database_file' => basename(DB_FILE),
            'columns' => ['archived_at', 'archive_previous_status'],
        ], 'failed', [
            'reason' => 'archive_schema_exception',
            'message' => $exception->getMessage(),
        ]);
        throw $exception;
    }
}

function ensureTaskPrioritySchema(PDO $pdo): void
{
    writeDebugLog('task_priority_schema_check', [
        'database_file' => basename(DB_FILE),
        'column' => 'priority',
    ], 'started');

    try {
        $columnsStatement = $pdo->query('PRAGMA table_info(tasks)');
        $columns = $columnsStatement !== false ? $columnsStatement->fetchAll() : [];
        $hasPriorityColumn = false;

        foreach ($columns as $column) {
            if (is_array($column) && isset($column['name']) && (string) $column['name'] === 'priority') {
                $hasPriorityColumn = true;
                break;
            }
        }

        if (!$hasPriorityColumn) {
            $pdo->exec("ALTER TABLE tasks ADD COLUMN priority TEXT NOT NULL DEFAULT '" . DEFAULT_TASK_PRIORITY . "'");
            writeDebugLog('task_priority_schema_alter', [
                'database_file' => basename(DB_FILE),
                'column' => 'priority',
                'default_priority' => DEFAULT_TASK_PRIORITY,
            ], 'success');
        }

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_priority ON tasks(priority)');
        writeDebugLog('task_priority_schema_check', [
            'database_file' => basename(DB_FILE),
            'column' => 'priority',
            'index' => 'idx_tasks_priority',
        ], 'success', [
            'column_added' => !$hasPriorityColumn,
            'allowed_priorities' => ALLOWED_PRIORITIES,
        ]);
    } catch (Throwable $exception) {
        writeDebugLog('task_priority_schema_check', [
            'database_file' => basename(DB_FILE),
            'column' => 'priority',
        ], 'failed', [
            'reason' => 'priority_schema_exception',
            'message' => $exception->getMessage(),
        ]);
        throw $exception;
    }
}

function ensureTaskRecurrenceSchema(PDO $pdo): void
{
    writeDebugLog('task_recurrence_schema_check', [
        'database_file' => basename(DB_FILE),
        'tasks_column' => 'repeat_rule',
        'table' => 'task_recurrences',
    ], 'started');

    try {
        $columnsStatement = $pdo->query('PRAGMA table_info(tasks)');
        $columns = $columnsStatement !== false ? $columnsStatement->fetchAll() : [];
        $hasRepeatRuleColumn = false;

        foreach ($columns as $column) {
            if (is_array($column) && isset($column['name']) && (string) $column['name'] === 'repeat_rule') {
                $hasRepeatRuleColumn = true;
                break;
            }
        }

        if (!$hasRepeatRuleColumn) {
            $pdo->exec('ALTER TABLE tasks ADD COLUMN repeat_rule TEXT NULL');
            writeDebugLog('task_recurrence_schema_alter', [
                'database_file' => basename(DB_FILE),
                'table' => 'tasks',
                'column' => 'repeat_rule',
            ], 'success');
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS task_recurrences (
            id TEXT PRIMARY KEY,
            source_task_id TEXT NOT NULL,
            generated_task_id TEXT NOT NULL,
            repeat_rule TEXT NOT NULL,
            source_due_at TEXT NOT NULL,
            generated_due_at TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
            FOREIGN KEY (source_task_id) REFERENCES tasks(id) ON UPDATE CASCADE ON DELETE CASCADE,
            FOREIGN KEY (generated_task_id) REFERENCES tasks(id) ON UPDATE CASCADE ON DELETE CASCADE
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_repeat_rule ON tasks(repeat_rule)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_task_recurrences_source ON task_recurrences(source_task_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_task_recurrences_generated ON task_recurrences(generated_task_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_task_recurrences_cycle ON task_recurrences(source_task_id, source_due_at, repeat_rule)');

        writeDebugLog('task_recurrence_schema_check', [
            'database_file' => basename(DB_FILE),
            'tasks_column' => 'repeat_rule',
            'table' => 'task_recurrences',
        ], 'success', [
            'repeat_rule_column_added' => !$hasRepeatRuleColumn,
            'allowed_repeat_types' => ALLOWED_REPEAT_TYPES,
            'min_interval' => MIN_REPEAT_INTERVAL,
            'max_interval' => MAX_REPEAT_INTERVAL,
        ]);
    } catch (Throwable $exception) {
        writeDebugLog('task_recurrence_schema_check', [
            'database_file' => basename(DB_FILE),
            'tasks_column' => 'repeat_rule',
            'table' => 'task_recurrences',
        ], 'failed', [
            'reason' => 'recurrence_schema_exception',
            'message' => $exception->getMessage(),
        ]);
        throw $exception;
    }
}

function ensureTaskHistorySchema(PDO $pdo): void
{
    writeDebugLog('task_history_schema_check', [
        'database_file' => basename(DB_FILE),
        'table' => 'task_histories',
    ], 'started');

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS task_histories (
            id TEXT PRIMARY KEY,
            task_id TEXT NOT NULL,
            operation_type TEXT NOT NULL,
            field_changes_json TEXT NOT NULL DEFAULT '{}',
            result_status TEXT NOT NULL,
            result_json TEXT NOT NULL DEFAULT '{}',
            created_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_task_histories_task_id ON task_histories(task_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_task_histories_created_at ON task_histories(created_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_task_histories_task_created ON task_histories(task_id, created_at)');

        writeDebugLog('task_history_schema_check', [
            'database_file' => basename(DB_FILE),
            'table' => 'task_histories',
        ], 'success', [
            'indexes' => ['idx_task_histories_task_id', 'idx_task_histories_created_at', 'idx_task_histories_task_created'],
        ]);
    } catch (Throwable $exception) {
        writeDebugLog('task_history_schema_check', [
            'database_file' => basename(DB_FILE),
            'table' => 'task_histories',
        ], 'failed', [
            'reason' => 'history_schema_exception',
            'message' => $exception->getMessage(),
        ]);
        throw $exception;
    }
}

function upsertSystemSetting(PDO $pdo, string $key, string $value): void
{
    $statement = $pdo->prepare(
        "INSERT INTO system_settings (setting_key, setting_value, created_at, updated_at)
        VALUES (:setting_key, :setting_value, datetime('now', 'localtime'), datetime('now', 'localtime'))
        ON CONFLICT(setting_key) DO UPDATE SET
            setting_value = excluded.setting_value,
            updated_at = datetime('now', 'localtime')"
    );
    $statement->execute([
        ':setting_key' => $key,
        ':setting_value' => $value,
    ]);
}

function getSystemSetting(PDO $pdo, string $key): ?string
{
    $statement = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = :setting_key LIMIT 1');
    $statement->execute([':setting_key' => $key]);
    $value = $statement->fetchColumn();

    return is_string($value) ? $value : null;
}

function getAllUserSettings(PDO $pdo): array
{
    $statement = $pdo->query('SELECT setting_key, setting_value FROM system_settings');
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    $settings = [];
    foreach ($rows as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    return $settings;
}

function getEffectiveSetting(PDO $pdo, string $key, string $default): string
{
    $value = getSystemSetting($pdo, $key);
    return $value !== null ? $value : $default;
}

function validateSettingSortField(string $value): bool
{
    return in_array($value, ALLOWED_SORT_FIELDS, true);
}

function validateSettingSortOrder(string $value): bool
{
    return in_array($value, ALLOWED_SORT_ORDERS, true);
}

function validateSettingPageSize(int $value): bool
{
    return $value >= MIN_PAGE_SIZE && $value <= MAX_PAGE_SIZE;
}

function validateSettingPriority(string $value): bool
{
    return in_array($value, ALLOWED_PRIORITIES, true);
}

function validateSettingReminderLeadTime(int $value): bool
{
    return $value >= MIN_REMINDER_LEAD_TIME && $value <= MAX_REMINDER_LEAD_TIME;
}

function validateUserSettingsInput(array $input): array
{
    $errors = [];
    $sanitized = [];

    if (isset($input['default_sort_field'])) {
        $sortField = is_string($input['default_sort_field']) ? trim($input['default_sort_field']) : '';
        if ($sortField === '' || !validateSettingSortField($sortField)) {
            $errors['default_sort_field'] = '非法排序字段，已使用默认值 ' . DEFAULT_SORT_FIELD . '。';
            $sanitized['default_sort_field'] = DEFAULT_SORT_FIELD;
        } else {
            $sanitized['default_sort_field'] = $sortField;
        }
    } else {
        $sanitized['default_sort_field'] = DEFAULT_SORT_FIELD;
    }

    if (isset($input['default_sort_order'])) {
        $sortOrder = is_string($input['default_sort_order']) ? trim($input['default_sort_order']) : '';
        if ($sortOrder === '' || !validateSettingSortOrder($sortOrder)) {
            $errors['default_sort_order'] = '非法排序方向，已使用默认值 ' . DEFAULT_SORT_ORDER . '。';
            $sanitized['default_sort_order'] = DEFAULT_SORT_ORDER;
        } else {
            $sanitized['default_sort_order'] = $sortOrder;
        }
    } else {
        $sanitized['default_sort_order'] = DEFAULT_SORT_ORDER;
    }

    if (isset($input['default_page_size'])) {
        $pageSize = isset($input['default_page_size']) && is_numeric($input['default_page_size']) ? (int) $input['default_page_size'] : 0;
        if (!validateSettingPageSize($pageSize)) {
            $errors['default_page_size'] = '非法每页数量，已使用默认值 ' . DEFAULT_PAGE_SIZE . '。';
            $sanitized['default_page_size'] = (string) DEFAULT_PAGE_SIZE;
        } else {
            $sanitized['default_page_size'] = (string) $pageSize;
        }
    } else {
        $sanitized['default_page_size'] = (string) DEFAULT_PAGE_SIZE;
    }

    if (isset($input['default_priority'])) {
        $priority = is_string($input['default_priority']) ? trim($input['default_priority']) : '';
        if ($priority === '' || !validateSettingPriority($priority)) {
            $errors['default_priority'] = '非法默认优先级，已使用默认值 ' . DEFAULT_TASK_PRIORITY . '。';
            $sanitized['default_priority'] = DEFAULT_TASK_PRIORITY;
        } else {
            $sanitized['default_priority'] = $priority;
        }
    } else {
        $sanitized['default_priority'] = DEFAULT_TASK_PRIORITY;
    }

    if (isset($input['reminder_lead_time'])) {
        $leadTime = isset($input['reminder_lead_time']) && is_numeric($input['reminder_lead_time']) ? (int) $input['reminder_lead_time'] : -1;
        if (!validateSettingReminderLeadTime($leadTime)) {
            $errors['reminder_lead_time'] = '非法提醒提前时间，已使用默认值 ' . DEFAULT_REMINDER_LEAD_TIME . ' 分钟。';
            $sanitized['reminder_lead_time'] = (string) DEFAULT_REMINDER_LEAD_TIME;
        } else {
            $sanitized['reminder_lead_time'] = (string) $leadTime;
        }
    } else {
        $sanitized['reminder_lead_time'] = (string) DEFAULT_REMINDER_LEAD_TIME;
    }

    return [
        'errors' => $errors,
        'sanitized' => $sanitized,
    ];
}

function saveUserSettings(PDO $pdo, array $settings): bool
{
    foreach ($settings as $key => $value) {
        upsertSystemSetting($pdo, $key, $value);
    }
    return true;
}

function migrateLegacyTasksJson(PDO $pdo): void
{
    if (getSystemSetting($pdo, 'legacy_json_migrated') === '1') {
        return;
    }

    writeDebugLog('database_legacy_json_migration', [
        'data_file' => basename(DATA_FILE),
        'database_file' => basename(DB_FILE),
    ], 'started');

    $migratedCount = 0;
    $skippedCount = 0;

    try {
        if (file_exists(DATA_FILE)) {
            $rawJson = file_get_contents(DATA_FILE);
            if ($rawJson === false) {
                throw new RuntimeException('旧 JSON 数据文件读取失败。');
            }

            $trimmedJson = trim($rawJson);
            if ($trimmedJson !== '') {
                $decoded = json_decode($trimmedJson, true);
                if (!is_array($decoded)) {
                    throw new RuntimeException('旧 JSON 数据格式无效：' . json_last_error_msg());
                }

                $statement = $pdo->prepare(
                    "INSERT OR IGNORE INTO tasks
                    (id, title, content, status, priority, category_id, due_at, archived_at, archive_previous_status, deleted_at, created_at, updated_at)
                    VALUES
                    (:id, :title, :content, :status, :priority, NULL, NULL, NULL, NULL, NULL, :created_at, :updated_at)"
                );

                foreach ($decoded as $index => $legacyTask) {
                    if (!is_array($legacyTask)) {
                        $skippedCount++;
                        continue;
                    }

                    $task = normalizeTask($legacyTask, (int) $index);
                    $statement->execute([
                        ':id' => $task['id'],
                        ':title' => $task['title'],
                        ':content' => $task['content'],
                        ':status' => normalizeTaskStatus($task['status']),
                        ':priority' => '中',
                        ':created_at' => $task['created_at'],
                        ':updated_at' => $task['updated_at'],
                    ]);

                    if ($statement->rowCount() > 0) {
                        $migratedCount++;
                    } else {
                        $skippedCount++;
                    }
                }
            }
        }

        upsertSystemSetting($pdo, 'legacy_json_migrated', '1');
        writeDebugLog('database_legacy_json_migration', [
            'data_file' => basename(DATA_FILE),
            'database_file' => basename(DB_FILE),
        ], 'success', [
            'migrated_count' => $migratedCount,
            'skipped_count' => $skippedCount,
        ]);
    } catch (Throwable $exception) {
        writeDebugLog('database_legacy_json_migration', [
            'data_file' => basename(DATA_FILE),
            'database_file' => basename(DB_FILE),
        ], 'failed', [
            'reason' => 'migration_exception',
            'message' => $exception->getMessage(),
            'migrated_count' => $migratedCount,
            'skipped_count' => $skippedCount,
        ]);
        throw $exception;
    }
}

function migrateTaskStatuses(PDO $pdo): void
{
    if (getSystemSetting($pdo, 'task_status_migrated_v2') === '1') {
        return;
    }

    writeDebugLog('task_status_migration', [
        'database_file' => basename(DB_FILE),
        'allowed_statuses' => ALLOWED_STATUSES,
        'legacy_statuses' => array_keys(LEGACY_STATUS_MAP),
    ], 'started');

    try {
        $statement = $pdo->query('SELECT id, status, archived_at FROM tasks WHERE deleted_at IS NULL');
        $rows = $statement !== false ? $statement->fetchAll() : [];
        $updatedCount = 0;
        $invalidCount = 0;
        $now = date('Y-m-d H:i:s');
        $updateStatement = $pdo->prepare(
            'UPDATE tasks
            SET status = :status,
                archived_at = :archived_at,
                updated_at = :updated_at
            WHERE id = :id'
        );

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $taskId = isset($row['id']) && is_string($row['id']) ? $row['id'] : '';
            $rawStatus = isset($row['status']) && is_string($row['status']) ? trim($row['status']) : '';
            $normalizedStatus = normalizeTaskStatus($rawStatus);
            $archivedAt = isset($row['archived_at']) && is_string($row['archived_at']) ? trim($row['archived_at']) : '';

            if ($taskId === '') {
                continue;
            }

            if ($rawStatus !== $normalizedStatus) {
                $invalidCount++;
                writeDebugLog('task_status_read_exception', [
                    'task_id' => $taskId,
                    'raw_status' => $rawStatus,
                ], 'failed', [
                    'reason' => 'status_outside_allowed_enum',
                    'normalized_status' => $normalizedStatus,
                    'allowed_statuses' => ALLOWED_STATUSES,
                ]);
            }

            $targetArchivedAt = $normalizedStatus === '已归档'
                ? ($archivedAt !== '' ? $archivedAt : $now)
                : null;

            if ($rawStatus !== $normalizedStatus || ($normalizedStatus !== '已归档' && $archivedAt !== '') || ($normalizedStatus === '已归档' && $archivedAt === '')) {
                $updateStatement->execute([
                    ':status' => $normalizedStatus,
                    ':archived_at' => $targetArchivedAt,
                    ':updated_at' => $now,
                    ':id' => $taskId,
                ]);
                $updatedCount += $updateStatement->rowCount();
            }
        }

        upsertSystemSetting($pdo, 'task_status_migrated_v2', '1');
        writeDebugLog('task_status_migration', [
            'database_file' => basename(DB_FILE),
        ], 'success', [
            'scanned_count' => count($rows),
            'updated_count' => $updatedCount,
            'invalid_status_count' => $invalidCount,
            'allowed_statuses' => ALLOWED_STATUSES,
        ]);
    } catch (Throwable $exception) {
        writeDebugLog('task_status_migration', [
            'database_file' => basename(DB_FILE),
        ], 'failed', [
            'reason' => 'status_migration_exception',
            'message' => $exception->getMessage(),
        ]);
        throw $exception;
    }
}

function migrateTaskPriorities(PDO $pdo): void
{
    if (getSystemSetting($pdo, 'task_priority_migrated_v1') === '1') {
        return;
    }

    writeDebugLog('task_priority_migration', [
        'database_file' => basename(DB_FILE),
        'allowed_priorities' => ALLOWED_PRIORITIES,
        'default_priority' => DEFAULT_TASK_PRIORITY,
    ], 'started');

    try {
        $statement = $pdo->query('SELECT id, priority FROM tasks WHERE deleted_at IS NULL');
        $rows = $statement !== false ? $statement->fetchAll() : [];
        $updatedCount = 0;
        $invalidCount = 0;
        $now = date('Y-m-d H:i:s');
        $updateStatement = $pdo->prepare(
            'UPDATE tasks
            SET priority = :priority,
                updated_at = :updated_at
            WHERE id = :id'
        );

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $taskId = isset($row['id']) && is_string($row['id']) ? $row['id'] : '';
            $rawPriority = isset($row['priority']) && is_string($row['priority']) ? trim($row['priority']) : '';
            $normalizedPriority = normalizeTaskPriority($rawPriority);

            if ($taskId === '') {
                continue;
            }

            if ($rawPriority !== $normalizedPriority) {
                $invalidCount++;
                writeDebugLog('task_priority_read_exception', [
                    'task_id' => $taskId,
                    'raw_priority' => $rawPriority,
                ], 'failed', [
                    'reason' => 'priority_outside_allowed_enum',
                    'normalized_priority' => $normalizedPriority,
                    'allowed_priorities' => ALLOWED_PRIORITIES,
                ]);

                $updateStatement->execute([
                    ':priority' => $normalizedPriority,
                    ':updated_at' => $now,
                    ':id' => $taskId,
                ]);
                $updatedCount += $updateStatement->rowCount();
            }
        }

        upsertSystemSetting($pdo, 'task_priority_migrated_v1', '1');
        writeDebugLog('task_priority_migration', [
            'database_file' => basename(DB_FILE),
        ], 'success', [
            'scanned_count' => count($rows),
            'updated_count' => $updatedCount,
            'invalid_priority_count' => $invalidCount,
            'allowed_priorities' => ALLOWED_PRIORITIES,
        ]);
    } catch (Throwable $exception) {
        writeDebugLog('task_priority_migration', [
            'database_file' => basename(DB_FILE),
        ], 'failed', [
            'reason' => 'priority_migration_exception',
            'message' => $exception->getMessage(),
        ]);
        throw $exception;
    }
}

function normalizeFilterParameters(array $query): array
{
    $rawKeyword = isset($query['keyword']) && is_string($query['keyword']) ? $query['keyword'] : '';
    $rawStatus = isset($query['status']) && is_string($query['status']) ? $query['status'] : '';
    $rawPriority = isset($query['priority']) && is_string($query['priority']) ? $query['priority'] : '';
    $rawTagId = isset($query['tag_id']) && is_string($query['tag_id']) ? $query['tag_id'] : '';
    $keyword = trim($rawKeyword);
    $status = trim($rawStatus);
    $priority = trim($rawPriority);
    $tagId = trim($rawTagId);
    $errors = [];

    if (stringLength($keyword) > MAX_SEARCH_KEYWORD_LENGTH) {
        $errors['keyword'] = '关键词不能超过 ' . MAX_SEARCH_KEYWORD_LENGTH . ' 个字符，已自动截断后搜索。';
        if (function_exists('mb_substr')) {
            $keyword = mb_substr($keyword, 0, MAX_SEARCH_KEYWORD_LENGTH, 'UTF-8');
        } else {
            $keyword = substr($keyword, 0, MAX_SEARCH_KEYWORD_LENGTH);
        }
    }

    if ($status !== '' && !isAllowedTaskStatus($status)) {
        $errors['status'] = '状态筛选参数无效，已忽略该条件。';
        $status = '';
    }

    if ($priority !== '' && !isAllowedTaskPriority($priority)) {
        $errors['priority'] = '优先级筛选参数无效，已忽略该条件。';
        $priority = '';
    }

    if ($tagId !== '' && !preg_match('/^tag-\d+-[a-f0-9]+$/', $tagId)) {
        $errors['tag_id'] = '标签筛选参数无效，已忽略该条件。';
        $tagId = '';
    }

    return [
        'keyword' => $keyword,
        'status' => $status,
        'priority' => $priority,
        'tag_id' => $tagId,
        'errors' => $errors,
        'raw_keyword_length' => stringLength($rawKeyword),
        'raw_status' => $rawStatus,
        'raw_priority' => $rawPriority,
        'raw_tag_id' => $rawTagId,
    ];
}

function filterTasks(array $tasks, string $keyword, string $status, string $priority, string $tagId = ''): array
{
    if ($keyword === '' && $status === '' && $priority === '' && $tagId === '') {
        return $tasks;
    }

    $filteredTasks = [];
    foreach ($tasks as $task) {
        $matchesKeyword = $keyword === ''
            || stringContainsInsensitive((string) ($task['title'] ?? ''), $keyword)
            || stringContainsInsensitive((string) ($task['content'] ?? ''), $keyword);
        $matchesStatus = $status === '' || (string) ($task['status'] ?? '') === $status;
        $matchesPriority = $priority === '' || normalizeTaskPriority((string) ($task['priority'] ?? '')) === $priority;
        $matchesTag = $tagId === '' || in_array($tagId, $task['tag_ids'] ?? [], true);

        if ($matchesKeyword && $matchesStatus && $matchesPriority && $matchesTag) {
            $filteredTasks[] = $task;
        }
    }

    return $filteredTasks;
}

function normalizeSortParameters(array $query): array
{
    $rawSortBy = isset($query['sort_by']) && is_string($query['sort_by']) ? trim($query['sort_by']) : '';
    $rawSortOrder = isset($query['sort_order']) && is_string($query['sort_order']) ? trim($query['sort_order']) : '';
    $sortBy = $rawSortBy;
    $sortOrder = $rawSortOrder;
    $errors = [];

    if ($rawSortBy !== '' && !in_array($rawSortBy, ALLOWED_SORT_FIELDS, true)) {
        $errors['sort_by'] = '排序字段参数无效，已使用默认排序。';
        $sortBy = DEFAULT_SORT_FIELD;
        writeDebugLog('task_sort_parameter_exception', [
            'submitted_sort_by' => $rawSortBy,
        ], 'failed', [
            'reason' => 'invalid_sort_field',
            'allowed_fields' => ALLOWED_SORT_FIELDS,
            'default_field_applied' => DEFAULT_SORT_FIELD,
        ]);
    }

    if ($rawSortOrder !== '' && !in_array($rawSortOrder, ALLOWED_SORT_ORDERS, true)) {
        $errors['sort_order'] = '排序方向参数无效，已使用默认排序。';
        $sortOrder = DEFAULT_SORT_ORDER;
        writeDebugLog('task_sort_parameter_exception', [
            'submitted_sort_order' => $rawSortOrder,
        ], 'failed', [
            'reason' => 'invalid_sort_order',
            'allowed_orders' => ALLOWED_SORT_ORDERS,
            'default_order_applied' => DEFAULT_SORT_ORDER,
        ]);
    }

    if ($sortBy === '') {
        $sortBy = DEFAULT_SORT_FIELD;
    }
    if ($sortOrder === '') {
        $sortOrder = DEFAULT_SORT_ORDER;
    }

    return [
        'sort_by' => $sortBy,
        'sort_order' => $sortOrder,
        'errors' => $errors,
        'raw_sort_by' => $rawSortBy,
        'raw_sort_order' => $rawSortOrder,
    ];
}

function normalizePaginationParameters(array $query): array
{
    $rawPage = isset($query['page']) && is_string($query['page']) ? trim($query['page']) : '';
    $rawPageSize = isset($query['page_size']) && is_string($query['page_size']) ? trim($query['page_size']) : '';
    $page = DEFAULT_PAGE_NUMBER;
    $pageSize = DEFAULT_PAGE_SIZE;
    $errors = [];

    if ($rawPage !== '') {
        $parsedPage = filter_var($rawPage, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($parsedPage === false) {
            $errors['page'] = '页码参数无效，已重置为第1页。';
            writeDebugLog('pagination_parameter_exception', [
                'submitted_page' => $rawPage,
            ], 'failed', [
                'reason' => 'invalid_page_number',
                'allowed_range' => '>= 1',
                'default_applied' => DEFAULT_PAGE_NUMBER,
            ]);
            $page = DEFAULT_PAGE_NUMBER;
        } else {
            $page = $parsedPage;
        }
    }

    if ($rawPageSize !== '') {
        $parsedPageSize = filter_var($rawPageSize, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => MIN_PAGE_SIZE, 'max_range' => MAX_PAGE_SIZE],
        ]);
        if ($parsedPageSize === false) {
            $errors['page_size'] = '每页数量参数无效，已使用默认值' . DEFAULT_PAGE_SIZE . '。';
            writeDebugLog('pagination_parameter_exception', [
                'submitted_page_size' => $rawPageSize,
            ], 'failed', [
                'reason' => 'invalid_page_size',
                'allowed_range' => MIN_PAGE_SIZE . ' - ' . MAX_PAGE_SIZE,
                'default_applied' => DEFAULT_PAGE_SIZE,
            ]);
            $pageSize = DEFAULT_PAGE_SIZE;
        } else {
            $pageSize = $parsedPageSize;
        }
    }

    return [
        'page' => $page,
        'page_size' => $pageSize,
        'errors' => $errors,
        'raw_page' => $rawPage,
        'raw_page_size' => $rawPageSize,
    ];
}

function calculatePagination(int $totalCount, int $page, int $pageSize): array
{
    $totalPages = $pageSize > 0 ? (int) ceil($totalCount / $pageSize) : 1;
    if ($totalPages < 1) {
        $totalPages = 1;
    }
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $pageSize;
    $hasNextPage = $page < $totalPages;
    $hasPrevPage = $page > 1;

    return [
        'total_count' => $totalCount,
        'total_pages' => $totalPages,
        'current_page' => $page,
        'page_size' => $pageSize,
        'offset' => $offset,
        'has_next_page' => $hasNextPage,
        'has_prev_page' => $hasPrevPage,
        'is_first_page' => $page === 1,
        'is_last_page' => $page === $totalPages,
    ];
}

function sortTasks(array $tasks, string $sortBy, string $sortOrder): array
{
    if (empty($tasks) || $sortBy === '') {
        return $tasks;
    }

    $isAsc = $sortOrder === 'asc';

    $priorityWeight = ['高' => 1, '中' => 2, '低' => 3];
    $statusWeight = ['未开始' => 1, '进行中' => 2, '已完成' => 3, '已归档' => 4];

    usort($tasks, function ($a, $b) use ($sortBy, $isAsc, $priorityWeight, $statusWeight) {
        $valueA = null;
        $valueB = null;

        switch ($sortBy) {
            case 'created_at':
                $valueA = strtotime($a['created_at'] ?? '1970-01-01 00:00:00');
                $valueB = strtotime($b['created_at'] ?? '1970-01-01 00:00:00');
                break;
            case 'updated_at':
                $valueA = strtotime($a['updated_at'] ?? '1970-01-01 00:00:00');
                $valueB = strtotime($b['updated_at'] ?? '1970-01-01 00:00:00');
                break;
            case 'due_at':
                $dueA = normalizeStoredDueAt($a['due_at'] ?? '');
                $dueB = normalizeStoredDueAt($b['due_at'] ?? '');
                if ($dueA === '' && $dueB === '') {
                    $valueA = PHP_INT_MAX;
                    $valueB = PHP_INT_MAX;
                } elseif ($dueA === '') {
                    $valueA = PHP_INT_MAX;
                    $valueB = strtotime($dueB);
                } elseif ($dueB === '') {
                    $valueA = strtotime($dueA);
                    $valueB = PHP_INT_MAX;
                } else {
                    $valueA = strtotime($dueA);
                    $valueB = strtotime($dueB);
                }
                break;
            case 'priority':
                $pA = normalizeTaskPriority($a['priority'] ?? '中');
                $pB = normalizeTaskPriority($b['priority'] ?? '中');
                $valueA = $priorityWeight[$pA] ?? 4;
                $valueB = $priorityWeight[$pB] ?? 4;
                break;
            case 'status':
                $sA = normalizeTaskStatus($a['status'] ?? '未开始');
                $sB = normalizeTaskStatus($b['status'] ?? '未开始');
                $valueA = $statusWeight[$sA] ?? 5;
                $valueB = $statusWeight[$sB] ?? 5;
                break;
            case 'title':
                $valueA = $a['title'] ?? '';
                $valueB = $b['title'] ?? '';
                $cmp = strcasecmp($valueA, $valueB);
                return $isAsc ? $cmp : -$cmp;
            default:
                return 0;
        }

        if ($valueA === $valueB) {
            return 0;
        }

        return $isAsc ? ($valueA > $valueB ? 1 : -1) : ($valueA > $valueB ? -1 : 1);
    });

    return $tasks;
}

function normalizeTagName(string $name): string
{
    $normalized = preg_replace('/\s+/u', ' ', trim($name));
    return is_string($normalized) ? $normalized : trim($name);
}

function createTagId(): string
{
    try {
        return 'tag-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
    } catch (Throwable $exception) {
        return 'tag-' . date('YmdHis') . '-' . str_replace('.', '', uniqid('', true));
    }
}

function loadTags(): array
{
    writeDebugLog('tag_list_load', [
        'database_file' => basename(DB_FILE),
    ], 'started');

    try {
        $pdo = getDatabaseConnection();
        $statement = $pdo->query(
            "SELECT t.id,
                    t.name,
                    t.color,
                    t.created_at,
                    t.updated_at,
                    COUNT(DISTINCT tt.task_id) AS task_count
            FROM tags t
            LEFT JOIN task_tags tt ON tt.tag_id = t.id
            LEFT JOIN tasks ta ON ta.id = tt.task_id AND ta.deleted_at IS NULL
            GROUP BY t.id, t.name, t.color, t.created_at, t.updated_at
            ORDER BY t.name COLLATE NOCASE ASC"
        );
        $rows = $statement !== false ? $statement->fetchAll() : [];
        $tags = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $tags[] = [
                'id' => isset($row['id']) && is_string($row['id']) ? trim($row['id']) : '',
                'name' => isset($row['name']) && is_string($row['name']) ? trim($row['name']) : '',
                'color' => isset($row['color']) && is_string($row['color']) && trim($row['color']) !== '' ? trim($row['color']) : '#667085',
                'created_at' => isset($row['created_at']) && is_string($row['created_at']) ? trim($row['created_at']) : '',
                'updated_at' => isset($row['updated_at']) && is_string($row['updated_at']) ? trim($row['updated_at']) : '',
                'task_count' => isset($row['task_count']) ? (int) $row['task_count'] : 0,
            ];
        }

        writeDebugLog('tag_list_load', [
            'database_file' => basename(DB_FILE),
        ], 'success', [
            'tag_count' => count($tags),
        ]);

        return $tags;
    } catch (Throwable $exception) {
        writeDebugLog('tag_list_load_exception', [
            'database_file' => basename(DB_FILE),
        ], 'failed', [
            'reason' => 'database_query_exception',
            'message' => $exception->getMessage(),
        ]);
        return [];
    }
}

function findTagById(array $tags, string $tagId): ?array
{
    foreach ($tags as $tag) {
        if (($tag['id'] ?? '') === $tagId) {
            return $tag;
        }
    }

    return null;
}

function tagNameExists(PDO $pdo, string $name, string $excludeTagId = ''): bool
{
    $statement = $pdo->prepare(
        "SELECT id FROM tags
        WHERE lower(name) = lower(:name)
            AND (:exclude_id = '' OR id <> :exclude_id)
        LIMIT 1"
    );
    $statement->execute([
        ':name' => $name,
        ':exclude_id' => $excludeTagId,
    ]);
    $existingId = $statement->fetchColumn();
    $statement->closeCursor();

    return is_string($existingId) && $existingId !== '';
}

function validateTagInput(string $name, string $excludeTagId = ''): array
{
    $errors = [];
    $normalizedName = normalizeTagName($name);

    if ($normalizedName === '') {
        $errors['tag_name'] = '标签名称不能为空。';
    } elseif (stringLength($normalizedName) > MAX_TAG_NAME_LENGTH) {
        $errors['tag_name'] = '标签名称不能超过 ' . MAX_TAG_NAME_LENGTH . ' 个字符。';
    } else {
        try {
            $pdo = getDatabaseConnection();
            if (tagNameExists($pdo, $normalizedName, $excludeTagId)) {
                $errors['tag_name'] = '标签名称已存在，请使用不同名称。';
            }
        } catch (Throwable $exception) {
            $errors['tag_name'] = '标签校验失败，请稍后重试。';
            writeDebugLog('tag_validation_exception', [
                'tag_id' => $excludeTagId,
                'name_length' => stringLength($normalizedName),
            ], 'failed', [
                'reason' => 'database_query_exception',
                'message' => $exception->getMessage(),
            ]);
        }
    }

    return [
        'valid' => $errors === [],
        'name' => $normalizedName,
        'errors' => $errors,
    ];
}

function saveNewTag(string $name, string $color = '#2563eb'): string
{
    $tagId = createTagId();
    writeDebugLog('tag_create', [
        'tag_id' => $tagId,
        'name_length' => stringLength($name),
        'color' => $color,
    ], 'started');

    try {
        $pdo = getDatabaseConnection();
        $now = date('Y-m-d H:i:s');

        $statement = $pdo->prepare(
            "INSERT INTO tags (id, name, color, created_at, updated_at)
            VALUES (:id, :name, :color, :created_at, :updated_at)"
        );
        $statement->execute([
            ':id' => $tagId,
            ':name' => $name,
            ':color' => $color,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $statement->closeCursor();

        writeDebugLog('tag_create', [
            'tag_id' => $tagId,
            'name_length' => stringLength($name),
            'color' => $color,
        ], 'success', [
            'database_file' => basename(DB_FILE),
            'created_at' => $now,
        ]);

        return 'success';
    } catch (Throwable $exception) {
        writeDebugLog('tag_create', [
            'tag_id' => $tagId,
            'name_length' => stringLength($name),
            'color' => $color,
        ], 'failed', [
            'reason' => 'database_write_exception',
            'message' => $exception->getMessage(),
            'database_file' => basename(DB_FILE),
        ]);
        return 'exception';
    }
}

function saveUpdatedTag(string $tagId, string $name, string $color): string
{
    writeDebugLog('tag_update', [
        'tag_id' => $tagId,
        'name_length' => stringLength($name),
        'color' => $color,
    ], 'started');

    try {
        $pdo = getDatabaseConnection();
        $existingStatement = $pdo->prepare('SELECT id, name, color FROM tags WHERE id = :id LIMIT 1');
        $existingStatement->execute([':id' => $tagId]);
        $existingTag = $existingStatement->fetch();
        $existingStatement->closeCursor();

        if (!is_array($existingTag)) {
            writeDebugLog('tag_update', [
                'tag_id' => $tagId,
                'name_length' => stringLength($name),
                'color' => $color,
            ], 'failed', [
                'reason' => $tagId === '' ? 'empty_tag_id' : 'missing_tag',
            ]);
            return 'not_found';
        }

        $updatedAt = date('Y-m-d H:i:s');
        $statement = $pdo->prepare(
            "UPDATE tags
            SET name = :name,
                color = :color,
                updated_at = :updated_at
            WHERE id = :id"
        );
        $statement->execute([
            ':name' => $name,
            ':color' => $color,
            ':updated_at' => $updatedAt,
            ':id' => $tagId,
        ]);
        $statement->closeCursor();

        writeDebugLog('tag_update', [
            'tag_id' => $tagId,
            'name_length' => stringLength($name),
            'color' => $color,
        ], 'success', [
            'previous_name' => (string) $existingTag['name'],
            'previous_color' => (string) $existingTag['color'],
            'new_name' => $name,
            'new_color' => $color,
            'changed' => (string) $existingTag['name'] !== $name || (string) $existingTag['color'] !== $color,
            'updated_at' => $updatedAt,
            'database_file' => basename(DB_FILE),
        ]);

        return 'success';
    } catch (Throwable $exception) {
        writeDebugLog('tag_update', [
            'tag_id' => $tagId,
            'name_length' => stringLength($name),
            'color' => $color,
        ], 'failed', [
            'reason' => 'database_write_exception',
            'message' => $exception->getMessage(),
            'database_file' => basename(DB_FILE),
        ]);
        return 'exception';
    }
}

function deleteTagById(string $tagId): string
{
    writeDebugLog('tag_delete', [
        'tag_id' => $tagId,
    ], 'started');

    try {
        $pdo = getDatabaseConnection();
        $existingStatement = $pdo->prepare('SELECT id, name FROM tags WHERE id = :id LIMIT 1');
        $existingStatement->execute([':id' => $tagId]);
        $existingTag = $existingStatement->fetch();
        $existingStatement->closeCursor();

        if (!is_array($existingTag)) {
            writeDebugLog('tag_delete', [
                'tag_id' => $tagId,
            ], 'failed', [
                'reason' => $tagId === '' ? 'empty_tag_id' : 'missing_tag',
            ]);
            return 'not_found';
        }

        $deleteStatement = $pdo->prepare('DELETE FROM tags WHERE id = :id');
        $deleteStatement->execute([':id' => $tagId]);
        $changedRows = $deleteStatement->rowCount();
        $deleteStatement->closeCursor();

        writeDebugLog('tag_delete', [
            'tag_id' => $tagId,
            'tag_name' => (string) $existingTag['name'],
        ], 'success', [
            'changed_rows' => $changedRows,
            'database_file' => basename(DB_FILE),
        ]);

        return 'success';
    } catch (Throwable $exception) {
        writeDebugLog('tag_delete', [
            'tag_id' => $tagId,
        ], 'failed', [
            'reason' => 'database_write_exception',
            'message' => $exception->getMessage(),
            'database_file' => basename(DB_FILE),
        ]);
        return 'exception';
    }
}

function loadTagIdsForTask(PDO $pdo, string $taskId): array
{
    $statement = $pdo->prepare('SELECT tag_id FROM task_tags WHERE task_id = :task_id');
    $statement->execute([':task_id' => $taskId]);
    $rows = $statement->fetchAll();
    $statement->closeCursor();

    $tagIds = [];
    foreach ($rows as $row) {
        if (is_array($row) && isset($row['tag_id']) && is_string($row['tag_id'])) {
            $tagIds[] = $row['tag_id'];
        }
    }

    return $tagIds;
}

function loadTagsForTask(PDO $pdo, string $taskId): array
{
    $statement = $pdo->prepare(
        "SELECT t.id, t.name, t.color
        FROM tags t
        INNER JOIN task_tags tt ON tt.tag_id = t.id
        WHERE tt.task_id = :task_id
        ORDER BY t.name COLLATE NOCASE ASC"
    );
    $statement->execute([':task_id' => $taskId]);
    $rows = $statement->fetchAll();
    $statement->closeCursor();

    $tags = [];
    foreach ($rows as $row) {
        if (is_array($row)) {
            $tags[] = [
                'id' => isset($row['id']) && is_string($row['id']) ? trim($row['id']) : '',
                'name' => isset($row['name']) && is_string($row['name']) ? trim($row['name']) : '',
                'color' => isset($row['color']) && is_string($row['color']) ? trim($row['color']) : '#667085',
            ];
        }
    }

    return $tags;
}

function assignTagsToTask(string $taskId, array $tagIds): bool
{
    writeDebugLog('tag_assign', [
        'task_id' => $taskId,
        'tag_ids' => $tagIds,
    ], 'started');

    try {
        $pdo = getDatabaseConnection();
        $now = date('Y-m-d H:i:s');
        $previousTagIds = loadTagIdsForTask($pdo, $taskId);
        sort($previousTagIds);
        $normalizedTagIds = [];
        foreach ($tagIds as $tagId) {
            if (is_string($tagId) && trim($tagId) !== '') {
                $normalizedTagIds[] = trim($tagId);
            }
        }
        $normalizedTagIds = array_values(array_unique($normalizedTagIds));
        sort($normalizedTagIds);

        $deleteStatement = $pdo->prepare('DELETE FROM task_tags WHERE task_id = :task_id');
        $deleteStatement->execute([':task_id' => $taskId]);
        $deleteStatement->closeCursor();

        if (!empty($normalizedTagIds)) {
            $insertStatement = $pdo->prepare(
                "INSERT INTO task_tags (task_id, tag_id, created_at)
                VALUES (:task_id, :tag_id, :created_at)"
            );

            foreach ($normalizedTagIds as $tagId) {
                $insertStatement->execute([
                    ':task_id' => $taskId,
                    ':tag_id' => $tagId,
                    ':created_at' => $now,
                ]);
            }

            $insertStatement->closeCursor();
        }

        writeDebugLog('tag_assign', [
            'task_id' => $taskId,
            'tag_ids' => $tagIds,
        ], 'success', [
            'tag_count' => count($normalizedTagIds),
            'database_file' => basename(DB_FILE),
        ]);

        $historyChanges = buildTaskFieldChanges([
            'tag_ids' => $previousTagIds,
        ], [
            'tag_ids' => $normalizedTagIds,
        ], ['tag_ids']);
        if ($historyChanges !== []) {
            $historyWritten = recordTaskHistory($taskId, 'tag_assign', $historyChanges, 'success', [
                'tag_count' => count($normalizedTagIds),
                'updated_at' => $now,
            ]);
            if (!$historyWritten) {
                writeDebugLog('task_tag_history_warning', [
                    'task_id' => $taskId,
                    'operation_type' => 'tag_assign',
                ], 'failed', [
                    'reason' => 'history_write_failed_after_tag_assign',
                    'message' => getLastTaskHistoryError(),
                    'main_operation_kept' => true,
                ]);
            }
        }

        return true;
    } catch (Throwable $exception) {
        writeDebugLog('tag_assign_exception', [
            'task_id' => $taskId,
            'tag_ids' => $tagIds,
        ], 'failed', [
            'reason' => 'database_write_exception',
            'message' => $exception->getMessage(),
            'database_file' => basename(DB_FILE),
        ]);
        return false;
    }
}

function findTagByNameForImport(PDO $pdo, string $name): ?array
{
    $normalizedName = normalizeTagName($name);
    if ($normalizedName === '') {
        return null;
    }
    $statement = $pdo->prepare('SELECT id, name, color FROM tags WHERE lower(name) = lower(:name) LIMIT 1');
    $statement->execute([':name' => $normalizedName]);
    $row = $statement->fetch();
    $statement->closeCursor();
    if (!is_array($row)) {
        return null;
    }
    return [
        'id' => isset($row['id']) && is_string($row['id']) ? trim($row['id']) : '',
        'name' => isset($row['name']) && is_string($row['name']) ? trim($row['name']) : '',
        'color' => isset($row['color']) && is_string($row['color']) ? trim($row['color']) : '#667085',
    ];
}

function findOrCreateTagForImport(PDO $pdo, string $name): array
{
    $normalizedName = normalizeTagName($name);
    if ($normalizedName === '') {
        return ['id' => '', 'name' => '', 'color' => '#667085', 'created' => false];
    }
    $existing = findTagByNameForImport($pdo, $normalizedName);
    if ($existing !== null) {
        return ['id' => $existing['id'], 'name' => $existing['name'], 'color' => $existing['color'], 'created' => false];
    }
    $tagId = createTagId();
    $color = '#667085';
    $now = date('Y-m-d H:i:s');
    try {
        $statement = $pdo->prepare(
            "INSERT INTO tags (id, name, color, created_at, updated_at)
            VALUES (:id, :name, :color, :created_at, :updated_at)"
        );
        $statement->execute([
            ':id' => $tagId,
            ':name' => $normalizedName,
            ':color' => $color,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $statement->closeCursor();
        writeDebugLog('csv_import_tag_created', [
            'tag_id' => $tagId,
            'tag_name' => $normalizedName,
        ], 'success', [
            'database_file' => basename(DB_FILE),
        ]);
        return ['id' => $tagId, 'name' => $normalizedName, 'color' => $color, 'created' => true];
    } catch (Throwable $exception) {
        writeDebugLog('csv_import_tag_create_failed', [
            'tag_name' => $normalizedName,
            'message' => $exception->getMessage(),
        ], 'failed', [
            'reason' => 'database_write_exception',
            'database_file' => basename(DB_FILE),
        ]);
        return ['id' => '', 'name' => '', 'color' => '#667085', 'created' => false];
    }
}

function findCategoryByNameForImport(PDO $pdo, string $name): ?array
{
    $normalizedName = normalizeCategoryName($name);
    if ($normalizedName === '') {
        return null;
    }
    $statement = $pdo->prepare('SELECT id, name, color FROM categories WHERE lower(name) = lower(:name) LIMIT 1');
    $statement->execute([':name' => $normalizedName]);
    $row = $statement->fetch();
    $statement->closeCursor();
    if (!is_array($row)) {
        return null;
    }
    return [
        'id' => isset($row['id']) && is_string($row['id']) ? trim($row['id']) : '',
        'name' => isset($row['name']) && is_string($row['name']) ? trim($row['name']) : '',
        'color' => isset($row['color']) && is_string($row['color']) ? trim($row['color']) : '#2563eb',
    ];
}

function findOrCreateCategoryForImport(PDO $pdo, string $name): array
{
    $normalizedName = normalizeCategoryName($name);
    if ($normalizedName === '') {
        return ['id' => '', 'name' => '', 'color' => '#2563eb', 'created' => false];
    }
    $existing = findCategoryByNameForImport($pdo, $normalizedName);
    if ($existing !== null) {
        return ['id' => $existing['id'], 'name' => $existing['name'], 'color' => $existing['color'], 'created' => false];
    }
    $categoryId = createCategoryId();
    $color = '#2563eb';
    $now = date('Y-m-d H:i:s');
    try {
        $statement = $pdo->prepare(
            "INSERT INTO categories (id, name, color, sort_order, created_at, updated_at)
            VALUES (:id, :name, :color, 0, :created_at, :updated_at)"
        );
        $statement->execute([
            ':id' => $categoryId,
            ':name' => $normalizedName,
            ':color' => $color,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $statement->closeCursor();
        writeDebugLog('csv_import_category_created', [
            'category_id' => $categoryId,
            'category_name' => $normalizedName,
        ], 'success', [
            'database_file' => basename(DB_FILE),
        ]);
        return ['id' => $categoryId, 'name' => $normalizedName, 'color' => $color, 'created' => true];
    } catch (Throwable $exception) {
        writeDebugLog('csv_import_category_create_failed', [
            'category_name' => $normalizedName,
            'message' => $exception->getMessage(),
        ], 'failed', [
            'reason' => 'database_write_exception',
            'database_file' => basename(DB_FILE),
        ]);
        return ['id' => '', 'name' => '', 'color' => '#2563eb', 'created' => false];
    }
}

function validateCsvRow(array $row, int $rowIndex, array $categories, array $tags): array
{
    $errors = [];
    $title = isset($row['title']) && is_string($row['title']) ? trim($row['title']) : '';
    $content = isset($row['content']) && is_string($row['content']) ? trim($row['content']) : '';
    $status = isset($row['status']) && is_string($row['status']) ? trim($row['status']) : '未开始';
    $priority = isset($row['priority']) && is_string($row['priority']) ? trim($row['priority']) : DEFAULT_TASK_PRIORITY;
    $categoryName = isset($row['category']) && is_string($row['category']) ? trim($row['category']) : '';
    $tagsInput = isset($row['tags']) && is_string($row['tags']) ? trim($row['tags']) : '';
    $dueAt = isset($row['due_at']) && is_string($row['due_at']) ? trim($row['due_at']) : '';

    if ($title === '') {
        $errors[] = '第 ' . $rowIndex . ' 行：标题不能为空';
    } elseif (stringLength($title) > MAX_TITLE_LENGTH) {
        $errors[] = '第 ' . $rowIndex . ' 行：标题不能超过 ' . MAX_TITLE_LENGTH . ' 个字符';
    }

    if (stringLength($content) > MAX_CONTENT_LENGTH) {
        $errors[] = '第 ' . $rowIndex . ' 行：内容不能超过 ' . MAX_CONTENT_LENGTH . ' 个字符';
    }

    if (!isAllowedTaskStatus($status)) {
        $errors[] = '第 ' . $rowIndex . ' 行：状态值 "' . $status . '" 无效，有效值为：' . implode('、', ALLOWED_STATUSES);
    }

    if (!isAllowedTaskPriority($priority)) {
        $errors[] = '第 ' . $rowIndex . ' 行：优先级值 "' . $priority . '" 无效，有效值为：' . implode('、', ALLOWED_PRIORITIES);
    }

    if ($categoryName !== '' && stringLength($categoryName) > MAX_CATEGORY_NAME_LENGTH) {
        $errors[] = '第 ' . $rowIndex . ' 行：分类名称不能超过 ' . MAX_CATEGORY_NAME_LENGTH . ' 个字符';
    }

    if ($tagsInput !== '' && stringLength($tagsInput) > MAX_TAG_INPUT_LENGTH) {
        $errors[] = '第 ' . $rowIndex . ' 行：标签总长度不能超过 ' . MAX_TAG_INPUT_LENGTH . ' 个字符';
    }

    if ($dueAt !== '') {
        $dueAtValidation = validateDueAtInput($dueAt);
        if (!$dueAtValidation['valid']) {
            $errors[] = '第 ' . $rowIndex . ' 行：截止日期格式无效';
        }
    }

    return $errors;
}

function parseTagsInput(string $tagsInput): array
{
    if ($tagsInput === '') {
        return [];
    }
    $tagNames = preg_split('/[,，;；]/u', $tagsInput);
    $normalized = [];
    foreach ($tagNames as $name) {
        $name = trim($name);
        if ($name !== '') {
            $normalized[] = $name;
        }
    }
    return $normalized;
}

function processCsvImport(array $rows, array $categories, array $tags): array
{
    writeDebugLog('csv_import_started', [
        'total_rows' => count($rows),
    ], 'started');

    $pdo = getDatabaseConnection();
    $successCount = 0;
    $failCount = 0;
    $failures = [];
    $createdTags = 0;
    $createdCategories = 0;
    $now = date('Y-m-d H:i:s');

    $header = $rows[0] ?? [];
    $header = array_map('strtolower', $header);
    $header = array_map('trim', $header);

    $requiredColumns = CSV_IMPORT_REQUIRED_COLUMNS;
    foreach ($requiredColumns as $required) {
        if (!in_array($required, $header, true)) {
            writeDebugLog('csv_import_failed', [
                'reason' => 'missing_required_column',
                'required_column' => $required,
            ], 'failed', [
                'total_rows' => count($rows),
            ]);
            return [
                'success' => false,
                'success_count' => 0,
                'fail_count' => 0,
                'failures' => ['CSV 文件缺少必需的列：' . $required],
                'created_tags' => 0,
                'created_categories' => 0,
            ];
        }
    }

    for ($i = 1; $i < count($rows); $i++) {
        if ($i > CSV_IMPORT_MAX_ROWS) {
            $failures[] = '超过最大导入行数限制 ' . CSV_IMPORT_MAX_ROWS . '，多余行被忽略';
            break;
        }

        $row = $rows[$i];
        if (!is_array($row) || count($row) !== count($header)) {
            $failures[] = '第 ' . $i . ' 行：数据格式错误';
            $failCount++;
            continue;
        }

        $rowData = array_combine($header, $row);
        if ($rowData === false) {
            $failures[] = '第 ' . $i . ' 行：数据格式错误';
            $failCount++;
            continue;
        }

        $rowErrors = validateCsvRow($rowData, $i, $categories, $tags);
        if ($rowErrors !== []) {
            foreach ($rowErrors as $err) {
                $failures[] = $err;
            }
            $failCount++;
            continue;
        }

        $title = trim($rowData['title']);
        $content = isset($rowData['content']) ? trim($rowData['content']) : '';
        $status = isset($rowData['status']) ? normalizeTaskStatus(trim($rowData['status'])) : '未开始';
        $priority = isset($rowData['priority']) ? normalizeTaskPriority(trim($rowData['priority'])) : DEFAULT_TASK_PRIORITY;
        $categoryName = isset($rowData['category']) ? trim($rowData['category']) : '';
        $tagsInput = isset($rowData['tags']) ? trim($rowData['tags']) : '';
        $dueAtInput = isset($rowData['due_at']) ? trim($rowData['due_at']) : '';

        $normalizedDueAt = '';
        if ($dueAtInput !== '') {
            $dueAtValidation = validateDueAtInput($dueAtInput);
            if ($dueAtValidation['valid']) {
                $normalizedDueAt = is_string($dueAtValidation['normalized']) ? $dueAtValidation['normalized'] : '';
            }
        }

        $categoryId = null;
        if ($categoryName !== '') {
            $categoryResult = findOrCreateCategoryForImport($pdo, $categoryName);
            if ($categoryResult['id'] !== '') {
                $categoryId = $categoryResult['id'];
                if ($categoryResult['created']) {
                    $createdCategories++;
                }
            }
        }

        $tagIds = [];
        if ($tagsInput !== '') {
            $tagNames = parseTagsInput($tagsInput);
            foreach ($tagNames as $tagName) {
                $tagResult = findOrCreateTagForImport($pdo, $tagName);
                if ($tagResult['id'] !== '') {
                    $tagIds[] = $tagResult['id'];
                    if ($tagResult['created']) {
                        $createdTags++;
                    }
                }
            }
        }

        $taskId = createTaskId();
        $normalizedStatus = normalizeTaskStatus($status);
        $archivedAt = $normalizedStatus === '已归档' ? $now : null;
        $archivePreviousStatus = $normalizedStatus === '已归档' ? '未开始' : null;

        try {
            $statement = $pdo->prepare(
                "INSERT INTO tasks
                (id, title, content, status, priority, category_id, due_at, repeat_rule, archived_at, archive_previous_status, deleted_at, created_at, updated_at)
                VALUES
                (:id, :title, :content, :status, :priority, :category_id, :due_at, NULL, :archived_at, :archive_previous_status, NULL, :created_at, :updated_at)"
            );
            $statement->execute([
                ':id' => $taskId,
                ':title' => $title,
                ':content' => $content,
                ':status' => $normalizedStatus,
                ':priority' => $priority,
                ':category_id' => $categoryId,
                ':due_at' => $normalizedDueAt !== '' ? $normalizedDueAt : null,
                ':archived_at' => $archivedAt,
                ':archive_previous_status' => $archivePreviousStatus,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
            $statement->closeCursor();

            if (!empty($tagIds)) {
                $insertTagStmt = $pdo->prepare(
                    "INSERT INTO task_tags (task_id, tag_id, created_at)
                    VALUES (:task_id, :tag_id, :created_at)"
                );
                foreach ($tagIds as $tagId) {
                    $insertTagStmt->execute([
                        ':task_id' => $taskId,
                        ':tag_id' => $tagId,
                        ':created_at' => $now,
                    ]);
                }
                $insertTagStmt->closeCursor();
            }

            writeDebugLog('csv_import_row_success', [
                'row_number' => $i,
                'task_id' => $taskId,
                'title' => $title,
            ], 'success', [
                'database_file' => basename(DB_FILE),
            ]);

            $successCount++;
        } catch (Throwable $exception) {
            $failures[] = '第 ' . $i . ' 行：保存失败 - ' . $exception->getMessage();
            $failCount++;
            writeDebugLog('csv_import_row_failed', [
                'row_number' => $i,
                'title' => $title,
                'message' => $exception->getMessage(),
            ], 'failed', [
                'reason' => 'database_write_exception',
                'database_file' => basename(DB_FILE),
            ]);
        }
    }

    writeDebugLog('csv_import_completed', [
        'success_count' => $successCount,
        'fail_count' => $failCount,
        'created_tags' => $createdTags,
        'created_categories' => $createdCategories,
    ], 'success', [
        'total_rows' => count($rows) - 1,
    ]);

    return [
        'success' => true,
        'success_count' => $successCount,
        'fail_count' => $failCount,
        'failures' => $failures,
        'created_tags' => $createdTags,
        'created_categories' => $createdCategories,
    ];
}

function handleCsvImport(): array
{
    writeDebugLog('csv_import_handle_start', [], 'started');

    if (!isset($_FILES['csv_file']) || !is_array($_FILES['csv_file'])) {
        writeDebugLog('csv_import_handle_failed', [
            'reason' => 'no_file_uploaded',
        ], 'failed');
        return [
            'success' => false,
            'message' => '未选择 CSV 文件',
            'success_count' => 0,
            'fail_count' => 0,
            'failures' => [],
        ];
    }

    $file = $_FILES['csv_file'];
    $error = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
    if ($error !== UPLOAD_ERR_OK) {
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE => '文件大小超过服务器限制',
            UPLOAD_ERR_FORM_SIZE => '文件大小超过表单限制',
            UPLOAD_ERR_PARTIAL => '文件只有部分被上传',
            UPLOAD_ERR_NO_FILE => '未选择文件',
        ];
        $message = $errorMessages[$error] ?? '未知上传错误';
        writeDebugLog('csv_import_handle_failed', [
            'reason' => 'upload_error',
            'error_code' => $error,
        ], 'failed');
        return [
            'success' => false,
            'message' => $message,
            'success_count' => 0,
            'fail_count' => 0,
            'failures' => [],
        ];
    }

    $fileSize = isset($file['size']) ? (int) $file['size'] : 0;
    if ($fileSize <= 0 || $fileSize > CSV_IMPORT_MAX_FILE_SIZE) {
        writeDebugLog('csv_import_handle_failed', [
            'reason' => 'file_size_invalid',
            'file_size' => $fileSize,
            'max_size' => CSV_IMPORT_MAX_FILE_SIZE,
        ], 'failed');
        return [
            'success' => false,
            'message' => '文件大小不能超过 ' . (CSV_IMPORT_MAX_FILE_SIZE / 1024 / 1024) . ' MB',
            'success_count' => 0,
            'fail_count' => 0,
            'failures' => [],
        ];
    }

    $fileName = isset($file['name']) && is_string($file['name']) ? trim($file['name']) : '';
    if ($fileName === '' || stringLength($fileName) > MAX_ATTACHMENT_FILE_NAME_LENGTH) {
        writeDebugLog('csv_import_handle_failed', [
            'reason' => 'file_name_invalid',
            'file_name' => $fileName,
        ], 'failed');
        return [
            'success' => false,
            'message' => '文件名无效',
            'success_count' => 0,
            'fail_count' => 0,
            'failures' => [],
        ];
    }

    $tmpName = isset($file['tmp_name']) && is_string($file['tmp_name']) ? $file['tmp_name'] : '';
    if ($tmpName === '' || !file_exists($tmpName) || !is_uploaded_file($tmpName)) {
        writeDebugLog('csv_import_handle_failed', [
            'reason' => 'tmp_file_not_found',
        ], 'failed');
        return [
            'success' => false,
            'message' => '上传文件无效',
            'success_count' => 0,
            'fail_count' => 0,
            'failures' => [],
        ];
    }

    $content = file_get_contents($tmpName);
    if ($content === false || $content === '') {
        writeDebugLog('csv_import_handle_failed', [
            'reason' => 'file_read_failed',
        ], 'failed');
        return [
            'success' => false,
            'message' => '无法读取上传的文件',
            'success_count' => 0,
            'fail_count' => 0,
            'failures' => [],
        ];
    }

    $content = trim($content);
    if (strlen($content) < 5) {
        writeDebugLog('csv_import_handle_failed', [
            'reason' => 'file_content_too_small',
        ], 'failed');
        return [
            'success' => false,
            'message' => 'CSV 文件内容为空或无效',
            'success_count' => 0,
            'fail_count' => 0,
            'failures' => [],
        ];
    }

    if (substr($content, 0, 3) === "\xef\xbb\xbf") {
        $content = substr($content, 3);
    }

    $rows = [];
    $lines = explode("\n", $content);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $row = str_getcsv($line);
        if ($row !== []) {
            $rows[] = $row;
        }
    }

    if (count($rows) < 2) {
        writeDebugLog('csv_import_handle_failed', [
            'reason' => 'not_enough_rows',
            'row_count' => count($rows),
        ], 'failed');
        return [
            'success' => false,
            'message' => 'CSV 文件至少需要包含表头和一行数据',
            'success_count' => 0,
            'fail_count' => 0,
            'failures' => [],
        ];
    }

    $categories = loadCategories();
    $tags = loadTags();

    $result = processCsvImport($rows, $categories, $tags);

    writeDebugLog('csv_import_handle_completed', [
        'success' => $result['success'],
        'success_count' => $result['success_count'],
        'fail_count' => $result['fail_count'],
    ], $result['success'] ? 'success' : 'failed');

    return $result;
}

function handleCsvExport(string $keyword = '', string $status = '', string $priority = '', string $tagId = '', string $visibility = TASK_VISIBILITY_ACTIVE): void
{
    writeDebugLog('csv_export_start', [
        'keyword_length' => stringLength($keyword),
        'status' => $status,
        'priority' => $priority,
        'tag_id' => $tagId,
        'visibility' => $visibility,
    ], 'started');

    try {
        $pdo = getDatabaseConnection();

        $whereConditions = [];
        appendTaskDeletionCondition($whereConditions, $visibility);
        appendTaskVisibilityCondition($whereConditions, $visibility);
        $params = [];

        if ($keyword !== '') {
            $whereConditions[] = '(t.title LIKE :keyword OR t.content LIKE :keyword)';
            $params[':keyword'] = '%' . $keyword . '%';
        }
        if ($status !== '' && isAllowedTaskStatus($status)) {
            $whereConditions[] = 't.status = :status';
            $params[':status'] = $status;
        }
        if ($priority !== '' && isAllowedTaskPriority($priority)) {
            $whereConditions[] = 't.priority = :priority';
            $params[':priority'] = $priority;
        }

        $whereClause = implode(' AND ', $whereConditions);

        if ($tagId !== '') {
            $sql = "SELECT t.id,
                    t.title,
                    t.content,
                    t.status,
                    t.priority,
                    t.category_id,
                    c.name AS category_name,
                    t.due_at,
                    t.repeat_rule,
                    t.created_at,
                    t.updated_at
            FROM tasks t
            LEFT JOIN categories c ON c.id = t.category_id
            INNER JOIN task_tags tt ON tt.task_id = t.id
            WHERE " . $whereClause . " AND tt.tag_id = :tag_id
            ORDER BY t.created_at DESC";
            $params[':tag_id'] = $tagId;
        } else {
            $sql = "SELECT t.id,
                    t.title,
                    t.content,
                    t.status,
                    t.priority,
                    t.category_id,
                    c.name AS category_name,
                    t.due_at,
                    t.repeat_rule,
                    t.created_at,
                    t.updated_at
            FROM tasks t
            LEFT JOIN categories c ON c.id = t.category_id
            WHERE " . $whereClause . "
            ORDER BY t.created_at DESC";
        }

        $statement = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value);
        }
        $statement->execute();
        $rows = $statement->fetchAll();
        $statement->closeCursor();

        $tasks = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $taskId = isset($row['id']) ? $row['id'] : '';
                $tagIds = loadTagIdsForTask($pdo, $taskId);
                $tags = loadTagsForTask($pdo, $taskId);
                $tagNames = array_column($tags, 'name');
                $row['tag_names'] = implode(',', $tagNames);
                $tasks[] = $row;
            }
        }

        writeDebugLog('csv_export_query_success', [
            'task_count' => count($tasks),
            'keyword_empty' => $keyword === '',
            'status_empty' => $status === '',
            'priority_empty' => $priority === '',
            'tag_id_empty' => $tagId === '',
        ], 'success');

        $csvContent = generateCsvContent($tasks);

        writeDebugLog('csv_export_content_generated', [
            'content_length' => stringLength($csvContent),
            'row_count' => count($tasks),
        ], 'success');

        $exportedAt = date('Y-m-d_H-i-s');
        $filename = 'tasks_export_' . $exportedAt . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo "\xef\xbb\xbf";
        echo $csvContent;

        writeDebugLog('csv_export_completed', [
            'filename' => $filename,
            'row_count' => count($tasks),
            'content_length' => stringLength($csvContent),
        ], 'success');

        exit;
    } catch (Throwable $exception) {
        writeDebugLog('csv_export_exception', [
            'keyword' => $keyword,
            'status' => $status,
            'priority' => $priority,
            'tag_id' => $tagId,
            'visibility' => $visibility,
        ], 'failed', [
            'reason' => 'export_exception',
            'message' => $exception->getMessage(),
        ]);
        setDatabaseErrorMessage('CSV 导出失败：' . $exception->getMessage());
    }
}

function generateCsvContent(array $tasks): string
{
    $headers = ['title', 'content', 'status', 'priority', 'category', 'tags', 'due_at', 'created_at', 'updated_at'];
    $lines = [];
    $lines[] = implode(',', $headers);

    if (empty($tasks)) {
        writeDebugLog('csv_export_empty_data', [], 'success');
        return implode("\n", $lines) . "\n";
    }

    foreach ($tasks as $task) {
        $row = [
            csvEscapeString(isset($task['title']) ? $task['title'] : ''),
            csvEscapeString(isset($task['content']) ? $task['content'] : ''),
            csvEscapeString(isset($task['status']) ? $task['status'] : ''),
            csvEscapeString(isset($task['priority']) ? $task['priority'] : ''),
            csvEscapeString(isset($task['category_name']) ? $task['category_name'] : ''),
            csvEscapeString(isset($task['tag_names']) ? $task['tag_names'] : ''),
            csvEscapeString(isset($task['due_at']) ? $task['due_at'] : ''),
            csvEscapeString(isset($task['created_at']) ? $task['created_at'] : ''),
            csvEscapeString(isset($task['updated_at']) ? $task['updated_at'] : ''),
        ];
        $lines[] = implode(',', $row);
    }

    writeDebugLog('csv_export_content_lines', [
        'line_count' => count($lines),
        'data_row_count' => count($tasks),
    ], 'success');

    return implode("\n", $lines) . "\n";
}

function csvEscapeString(string $value): string
{
    if ($value === '') {
        return '';
    }

    $needsQuoting = false;
    $escapedValue = $value;

    if (strpos($value, '"') !== false) {
        $escapedValue = str_replace('"', '""', $value);
        $needsQuoting = true;
    }

    if (strpos($value, ',') !== false || strpos($value, "\n") !== false || strpos($value, "\r") !== false) {
        $needsQuoting = true;
    }

    if ($needsQuoting) {
        return '"' . $escapedValue . '"';
    }

    return $value;
}

function ensureBackupDirectoryExists(): bool
{
    if (!is_dir(BACKUP_DIR)) {
        if (!mkdir(BACKUP_DIR, 0775, true) && !is_dir(BACKUP_DIR)) {
            writeDebugLog('backup_directory_create_failed', [
                'backup_dir' => BACKUP_DIR,
            ], 'failed', [
                'reason' => 'mkdir_failed',
            ]);
            return false;
        }
        writeDebugLog('backup_directory_created', [
            'backup_dir' => BACKUP_DIR,
        ], 'success');
    }
    return true;
}

function getBackupFilePath(): string
{
    $timestamp = date('Y-m-d_H-i-s');
    return BACKUP_DIR . '/' . BACKUP_PREFIX . $timestamp . BACKUP_FILE_EXTENSION;
}

function cleanupOldBackups(): void
{
    if (!is_dir(BACKUP_DIR)) {
        return;
    }

    $files = glob(BACKUP_DIR . '/' . BACKUP_PREFIX . '*' . BACKUP_FILE_EXTENSION);
    if ($files === false || count($files) <= MAX_BACKUP_FILES) {
        return;
    }

    usort($files, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });

    $filesToDelete = array_slice($files, MAX_BACKUP_FILES);
    foreach ($filesToDelete as $file) {
        if (file_exists($file)) {
            unlink($file);
            writeDebugLog('backup_cleanup_deleted', [
                'deleted_file' => basename($file),
            ], 'success');
        }
    }
}

function handleDatabaseBackup(): void
{
    writeDebugLog('database_backup_start', [
        'source_db' => basename(DB_FILE),
    ], 'started');

    try {
        if (!ensureBackupDirectoryExists()) {
            setDatabaseErrorMessage('无法创建备份目录。');
            return;
        }

        cleanupOldBackups();

        $backupPath = getBackupFilePath();

        $pdo = getDatabaseConnection();
        $pdo->exec('PRAGMA busy_timeout = 5000');

        $sourceDb = DB_FILE;
        $backupPdo = new PDO('sqlite:' . $backupPath);
        $backupPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $backupPdo->exec('PRAGMA busy_timeout = 5000');

        $tables = ['categories', 'tasks', 'tags', 'task_tags', 'subtasks', 'comments', 'attachments', 'reminders', 'system_settings', 'task_recurrences', 'task_histories'];
        foreach ($tables as $table) {
            $sourcePdo = new PDO('sqlite:' . $sourceDb);
            $sourcePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $sourcePdo->exec('PRAGMA busy_timeout = 5000');

            $sourcePdo->exec("ATTACH DATABASE ':memory:' AS temp_mem");
            $sourcePdo->exec("PRAGMA database_list");

            $createStmt = $sourcePdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='" . $table . "'");
            $createSql = $createStmt->fetchColumn();
            if ($createSql) {
                $backupPdo->exec($createSql);
            }

            $sourcePdo->exec("BEGIN");
            $result = $sourcePdo->query("SELECT * FROM " . $table);
            $rows = $result->fetchAll(PDO::FETCH_ASSOC);
            $result->closeCursor();

            if (!empty($rows)) {
                $columns = array_keys($rows[0]);
                $columnList = implode(', ', $columns);
                $placeholders = implode(', ', array_fill(0, count($columns), '?'));

                $insertSql = "INSERT INTO " . $table . " (" . $columnList . ") VALUES (" . $placeholders . ")";
                $insertStmt = $backupPdo->prepare($insertSql);

                foreach ($rows as $row) {
                    $values = array_values($row);
                    $insertStmt->execute($values);
                }
            }
            $sourcePdo->exec("COMMIT");

            $sourcePdo = null;
        }

        $backupMetaPdo = new PDO('sqlite:' . $backupPath);
        $backupMetaPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $backupMetaPdo->exec("CREATE TABLE IF NOT EXISTS backup_metadata (key TEXT PRIMARY KEY, value TEXT)");
        $backupMetaPdo->prepare("INSERT OR REPLACE INTO backup_metadata (key, value) VALUES ('version', :version)")->execute([':version' => BACKUP_VERSION]);
        $backupMetaPdo->prepare("INSERT OR REPLACE INTO backup_metadata (key, value) VALUES ('created_at', :created_at)")->execute([':created_at' => date('Y-m-d H:i:s')]);
        $backupMetaPdo->prepare("INSERT OR REPLACE INTO backup_metadata (key, value) VALUES ('original_db_size', :size)")->execute([':size' => (string) filesize(DB_FILE)]);
        $backupMetaPdo = null;

        $backupFileSize = filesize($backupPath);
        $taskCountPdo = new PDO('sqlite:' . DB_FILE);
        $taskCountPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $taskCountResult = $taskCountPdo->query("SELECT COUNT(*) FROM tasks");
        $taskCount = (int) $taskCountResult->fetchColumn();
        $taskCountResult->closeCursor();
        $taskCountPdo = null;

        writeDebugLog('database_backup_success', [
            'backup_file' => basename($backupPath),
            'backup_size' => $backupFileSize,
            'task_count' => $taskCount,
        ], 'success');

        $downloadFilename = 'tasks_backup_' . date('Y-m-d_H-i-s') . BACKUP_FILE_EXTENSION;

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $downloadFilename . '"');
        header('Content-Length: ' . $backupFileSize);
        header('Pragma: no-cache');
        header('Expires: 0');

        $downloadPdo = new PDO('sqlite:' . $backupPath);
        $downloadPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        readfile($backupPath);

        unlink($backupPath);

        writeDebugLog('database_backup_download_completed', [
            'filename' => $downloadFilename,
        ], 'success');

        exit;
    } catch (Throwable $exception) {
        writeDebugLog('database_backup_exception', [
            'source_db' => basename(DB_FILE),
        ], 'failed', [
            'reason' => 'backup_exception',
            'message' => $exception->getMessage(),
        ]);
        setDatabaseErrorMessage('数据库备份失败：' . $exception->getMessage());
    }
}

function validateBackupFile(string $filePath): array
{
    try {
        if (!file_exists($filePath)) {
            return ['valid' => false, 'error' => '备份文件不存在。', 'reason' => 'file_not_found'];
        }

        $fileSize = filesize($filePath);
        if ($fileSize === false) {
            return ['valid' => false, 'error' => '无法读取备份文件大小。', 'reason' => 'size_read_failed'];
        }

        if ($fileSize > MAX_RESTORE_FILE_SIZE) {
            return ['valid' => false, 'error' => '备份文件过大，最大支持 ' . (MAX_RESTORE_FILE_SIZE / 1024 / 1024) . ' MB。', 'reason' => 'file_too_large'];
        }

        if ($fileSize === 0) {
            return ['valid' => false, 'error' => '备份文件为空。', 'reason' => 'file_empty'];
        }

        $pdo = new PDO('sqlite:' . $filePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $tables = ['categories', 'tasks', 'tags', 'task_tags', 'subtasks', 'comments', 'attachments', 'reminders', 'system_settings', 'task_recurrences', 'task_histories'];
        foreach ($tables as $table) {
            $result = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='" . $table . "'");
            if ($result->fetchColumn() === false) {
                $pdo = null;
                return ['valid' => false, 'error' => '备份文件格式无效，缺少必要的表：' . $table, 'reason' => 'missing_table_' . $table];
            }
        }

        $versionResult = $pdo->query("SELECT value FROM backup_metadata WHERE key='version'");
        $version = $versionResult->fetchColumn();
        if ($version === false) {
            $pdo = null;
            return ['valid' => false, 'error' => '备份文件格式无效，缺少版本信息。', 'reason' => 'missing_version'];
        }

        $createdAtResult = $pdo->query("SELECT value FROM backup_metadata WHERE key='created_at'");
        $createdAt = $createdAtResult->fetchColumn();

        $pdo = null;

        return [
            'valid' => true,
            'version' => $version,
            'created_at' => $createdAt,
            'file_size' => $fileSize,
            'reason' => 'valid',
        ];
    } catch (Throwable $exception) {
        return ['valid' => false, 'error' => '备份文件验证失败：' . $exception->getMessage(), 'reason' => 'validation_exception'];
    }
}

function handleDatabaseRestore(): array
{
    writeDebugLog('database_restore_start', [
        'source_db' => basename(DB_FILE),
    ], 'started');

    try {
        if (!isset($_FILES['restore_file']) || !isset($_FILES['restore_file']['error'])) {
            writeDebugLog('database_restore_failed', [], 'failed', [
                'reason' => 'no_file_uploaded',
            ]);
            return ['success' => false, 'error' => '未检测到上传的备份文件。'];
        }

        $uploadError = $_FILES['restore_file']['error'];
        if ($uploadError !== UPLOAD_ERR_OK) {
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE => '文件大小超出服务器限制。',
                UPLOAD_ERR_FORM_SIZE => '文件大小超出表单限制。',
                UPLOAD_ERR_PARTIAL => '文件只有部分被上传。',
                UPLOAD_ERR_NO_FILE => '没有文件被上传。',
                UPLOAD_ERR_NO_TMP_DIR => '缺少临时目录。',
                UPLOAD_ERR_CANT_WRITE => '文件写入失败。',
                UPLOAD_ERR_EXTENSION => '文件上传被扩展阻止。',
            ];
            $message = $errorMessages[$uploadError] ?? '未知上传错误。';
            writeDebugLog('database_restore_upload_failed', [
                'upload_error' => $uploadError,
            ], 'failed', [
                'reason' => 'upload_error',
            ]);
            return ['success' => false, 'error' => '文件上传失败：' . $message];
        }

        $tempFilePath = $_FILES['restore_file']['tmp_name'];
        $originalFileName = isset($_FILES['restore_file']['name']) ? basename($_FILES['restore_file']['name']) : 'unknown';

        if (!is_uploaded_file($tempFilePath)) {
            writeDebugLog('database_restore_failed', [], 'failed', [
                'reason' => 'not_uploaded_file',
            ]);
            return ['success' => false, 'error' => '无效的文件上传。'];
        }

        writeDebugLog('database_restore_file_received', [
            'original_filename' => $originalFileName,
            'temp_path' => $tempFilePath,
        ], 'started');

        $validation = validateBackupFile($tempFilePath);
        if (!$validation['valid']) {
            writeDebugLog('database_restore_validation_failed', [
                'reason' => $validation['reason'],
                'error' => $validation['error'],
            ], 'failed');
            @unlink($tempFilePath);
            return ['success' => false, 'error' => $validation['error']];
        }

        writeDebugLog('database_restore_validation_success', [
            'version' => $validation['version'],
            'created_at' => $validation['created_at'] ?? 'unknown',
            'file_size' => $validation['file_size'],
        ], 'success');

        if (!ensureBackupDirectoryExists()) {
            writeDebugLog('database_restore_failed', [], 'failed', [
                'reason' => 'backup_dir_unavailable',
            ]);
            @unlink($tempFilePath);
            return ['success' => false, 'error' => '无法访问备份目录。'];
        }

        $preRestoreBackupPath = getBackupFilePath();
        writeDebugLog('database_restore_prebackup_start', [
            'prebackup_path' => basename($preRestoreBackupPath),
        ], 'started');

        try {
            $preRestorePdoSource = new PDO('sqlite:' . DB_FILE);
            $preRestorePdoSource->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $preRestorePdoSource->exec('PRAGMA busy_timeout = 5000');

            $preRestorePdoBackup = new PDO('sqlite:' . $preRestoreBackupPath);
            $preRestorePdoBackup->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $preRestorePdoBackup->exec('PRAGMA busy_timeout = 5000');

            $tables = ['categories', 'tasks', 'tags', 'task_tags', 'subtasks', 'comments', 'attachments', 'reminders', 'system_settings', 'task_recurrences', 'task_histories'];
            foreach ($tables as $table) {
                $createStmt = $preRestorePdoSource->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='" . $table . "'");
                $createSql = $createStmt->fetchColumn();
                if ($createSql) {
                    $preRestorePdoBackup->exec($createSql);
                }

                $preRestorePdoSource->exec("BEGIN");
                $result = $preRestorePdoSource->query("SELECT * FROM " . $table);
                $rows = $result->fetchAll(PDO::FETCH_ASSOC);
                $result->closeCursor();

                if (!empty($rows)) {
                    $columns = array_keys($rows[0]);
                    $columnList = implode(', ', $columns);
                    $placeholders = implode(', ', array_fill(0, count($columns), '?'));

                    $insertSql = "INSERT INTO " . $table . " (" . $columnList . ") VALUES (" . $placeholders . ")";
                    $insertStmt = $preRestorePdoBackup->prepare($insertSql);

                    foreach ($rows as $row) {
                        $values = array_values($row);
                        $insertStmt->execute($values);
                    }
                }
                $preRestorePdoSource->exec("COMMIT");
            }

            $preRestorePdoBackup->exec("CREATE TABLE IF NOT EXISTS backup_metadata (key TEXT PRIMARY KEY, value TEXT)");
            $preRestorePdoBackup->prepare("INSERT OR REPLACE INTO backup_metadata (key, value) VALUES ('version', :version)")->execute([':version' => BACKUP_VERSION]);
            $preRestorePdoBackup->prepare("INSERT OR NOT REPLACE INTO backup_metadata (key, value) VALUES ('created_at', :created_at)")->execute([':created_at' => date('Y-m-d H:i:s')]);
            $preRestorePdoBackup->prepare("INSERT OR REPLACE INTO backup_metadata (key, value) VALUES ('type', :type)")->execute([':type' => 'pre_restore_backup']);

            $preRestorePdoSource = null;
            $preRestorePdoBackup = null;

            writeDebugLog('database_restore_prebackup_success', [
                'prebackup_path' => basename($preRestoreBackupPath),
            ], 'success');
        } catch (Throwable $preBackupException) {
            writeDebugLog('database_restore_prebackup_failed', [
                'reason' => $preBackupException->getMessage(),
            ], 'failed');
            @unlink($preRestoreBackupPath);
        }

        $originalDbSize = filesize(DB_FILE);

        $pdo = new PDO('sqlite:' . DB_FILE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA busy_timeout = 5000');

        $pdo->exec("BEGIN");

        $pdo->exec("DELETE FROM task_tags");
        $pdo->exec("DELETE FROM subtasks");
        $pdo->exec("DELETE FROM comments");
        $pdo->exec("DELETE FROM attachments");
        $pdo->exec("DELETE FROM reminders");
        $pdo->exec("DELETE FROM task_histories");
        $pdo->exec("DELETE FROM task_recurrences");
        $pdo->exec("DELETE FROM tasks");
        $pdo->exec("DELETE FROM tags");
        $pdo->exec("DELETE FROM categories");
        $pdo->exec("DELETE FROM system_settings");

        writeDebugLog('database_restore_cleared_original', [
            'original_db_size' => $originalDbSize,
        ], 'success');

        $restorePdo = new PDO('sqlite:' . $tempFilePath);
        $restorePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $restorePdo->exec('PRAGMA busy_timeout = 5000');

        $tables = ['categories', 'tasks', 'tags', 'task_tags', 'subtasks', 'comments', 'attachments', 'reminders', 'system_settings', 'task_recurrences', 'task_histories'];
        foreach ($tables as $table) {
            $result = $restorePdo->query("SELECT * FROM " . $table);
            $rows = $result->fetchAll(PDO::FETCH_ASSOC);
            $result->closeCursor();

            if (!empty($rows)) {
                $columns = array_keys($rows[0]);
                $columnList = implode(', ', $columns);
                $placeholders = implode(', ', array_fill(0, count($columns), '?'));

                $insertSql = "INSERT INTO " . $table . " (" . $columnList . ") VALUES (" . $placeholders . ")";
                $insertStmt = $pdo->prepare($insertSql);

                foreach ($rows as $row) {
                    $values = array_values($row);
                    $insertStmt->execute($values);
                }

                writeDebugLog('database_restore_table_imported', [
                    'table' => $table,
                    'row_count' => count($rows),
                ], 'success');
            }
        }

        $pdo->exec("COMMIT");

        $restorePdo = null;
        $pdo = null;

        @unlink($tempFilePath);

        $restoredDbSize = filesize(DB_FILE);
        $taskCountPdo = new PDO('sqlite:' . DB_FILE);
        $taskCountPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $taskCountResult = $taskCountPdo->query("SELECT COUNT(*) FROM tasks");
        $taskCount = (int) $taskCountResult->fetchColumn();
        $taskCountResult->closeCursor();
        $taskCountPdo = null;

        writeDebugLog('database_restore_success', [
            'backup_version' => $validation['version'],
            'backup_created_at' => $validation['created_at'] ?? 'unknown',
            'task_count' => $taskCount,
            'restored_db_size' => $restoredDbSize,
            'original_db_size' => $originalDbSize,
        ], 'success');

        return [
            'success' => true,
            'task_count' => $taskCount,
            'version' => $validation['version'],
            'created_at' => $validation['created_at'] ?? 'unknown',
        ];
    } catch (Throwable $exception) {
        writeDebugLog('database_restore_exception', [
            'source_db' => basename(DB_FILE),
        ], 'failed', [
            'reason' => 'restore_exception',
            'message' => $exception->getMessage(),
        ]);

        $preRestoreFiles = glob(BACKUP_DIR . '/*pre_restore*');
        if (!empty($preRestoreFiles)) {
            usort($preRestoreFiles, function($a, $b) {
                return filemtime($b) - filemtime($a);
            });
            $latestPreRestore = $preRestoreFiles[0];
            writeDebugLog('database_restore_rollback_available', [
                'pre_restore_backup' => basename($latestPreRestore),
            ], 'info');
        }

        return ['success' => false, 'error' => '数据库恢复失败：' . $exception->getMessage()];
    }
}

function removeTagsFromTask(string $taskId): bool
{
    writeDebugLog('tag_remove', [
        'task_id' => $taskId,
    ], 'started');

    try {
        $pdo = getDatabaseConnection();
        $deleteStatement = $pdo->prepare('DELETE FROM task_tags WHERE task_id = :task_id');
        $deleteStatement->execute([':task_id' => $taskId]);
        $changedRows = $deleteStatement->rowCount();
        $deleteStatement->closeCursor();

        writeDebugLog('tag_remove', [
            'task_id' => $taskId,
        ], 'success', [
            'removed_count' => $changedRows,
            'database_file' => basename(DB_FILE),
        ]);

        return true;
    } catch (Throwable $exception) {
        writeDebugLog('tag_remove_exception', [
            'task_id' => $taskId,
        ], 'failed', [
            'reason' => 'database_write_exception',
            'message' => $exception->getMessage(),
            'database_file' => basename(DB_FILE),
        ]);
        return false;
    }
}

function normalizeCategoryName(string $name): string
{
    $normalized = preg_replace('/\s+/u', ' ', trim($name));
    return is_string($normalized) ? $normalized : trim($name);
}

function createCategoryId(): string
{
    try {
        return 'category-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
    } catch (Throwable $exception) {
        return 'category-' . date('YmdHis') . '-' . str_replace('.', '', uniqid('', true));
    }
}

function loadCategories(): array
{
    writeDebugLog('category_list_load', [
        'database_file' => basename(DB_FILE),
    ], 'started');

    try {
        $pdo = getDatabaseConnection();
        $statement = $pdo->query(
            "SELECT c.id,
                    c.name,
                    c.color,
                    c.sort_order,
                    c.created_at,
                    c.updated_at,
                    COUNT(t.id) AS task_count,
                    SUM(CASE WHEN t.deleted_at IS NULL THEN 1 ELSE 0 END) AS active_task_count
            FROM categories c
            LEFT JOIN tasks t ON t.category_id = c.id
            GROUP BY c.id, c.name, c.color, c.sort_order, c.created_at, c.updated_at
            ORDER BY c.sort_order ASC, c.name COLLATE NOCASE ASC"
        );
        $rows = $statement !== false ? $statement->fetchAll() : [];
        $categories = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $categories[] = [
                'id' => isset($row['id']) && is_string($row['id']) ? trim($row['id']) : '',
                'name' => isset($row['name']) && is_string($row['name']) ? trim($row['name']) : '',
                'color' => isset($row['color']) && is_string($row['color']) && trim($row['color']) !== '' ? trim($row['color']) : '#2563eb',
                'sort_order' => isset($row['sort_order']) ? (int) $row['sort_order'] : 0,
                'created_at' => isset($row['created_at']) && is_string($row['created_at']) ? trim($row['created_at']) : '',
                'updated_at' => isset($row['updated_at']) && is_string($row['updated_at']) ? trim($row['updated_at']) : '',
                'task_count' => isset($row['task_count']) ? (int) $row['task_count'] : 0,
                'active_task_count' => isset($row['active_task_count']) ? (int) $row['active_task_count'] : 0,
            ];
        }

        writeDebugLog('category_list_load', [
            'database_file' => basename(DB_FILE),
        ], 'success', [
            'category_count' => count($categories),
        ]);

        return $categories;
    } catch (Throwable $exception) {
        writeDebugLog('category_list_load_exception', [
            'database_file' => basename(DB_FILE),
        ], 'failed', [
            'reason' => 'database_query_exception',
            'message' => $exception->getMessage(),
        ]);
        return [];
    }
}

function findCategoryById(array $categories, string $categoryId): ?array
{
    foreach ($categories as $category) {
        if (($category['id'] ?? '') === $categoryId) {
            return $category;
        }
    }

    return null;
}

function categoryNameExists(PDO $pdo, string $name, string $excludeCategoryId = ''): bool
{
    $statement = $pdo->prepare(
        "SELECT id FROM categories
        WHERE lower(name) = lower(:name)
            AND (:exclude_id = '' OR id <> :exclude_id)
        LIMIT 1"
    );
    $statement->execute([
        ':name' => $name,
        ':exclude_id' => $excludeCategoryId,
    ]);
    $existingId = $statement->fetchColumn();
    $statement->closeCursor();

    return is_string($existingId) && $existingId !== '';
}

function validateCategoryInput(string $name, string $excludeCategoryId = ''): array
{
    $errors = [];
    $normalizedName = normalizeCategoryName($name);

    if ($normalizedName === '') {
        $errors['category_name'] = '分类名称不能为空。';
    } elseif (stringLength($normalizedName) > MAX_CATEGORY_NAME_LENGTH) {
        $errors['category_name'] = '分类名称不能超过 ' . MAX_CATEGORY_NAME_LENGTH . ' 个字符。';
    } else {
        try {
            $pdo = getDatabaseConnection();
            if (categoryNameExists($pdo, $normalizedName, $excludeCategoryId)) {
                $errors['category_name'] = '分类名称已存在，请使用不同名称。';
            }
        } catch (Throwable $exception) {
            $errors['category_name'] = '分类校验失败，请稍后重试。';
            writeDebugLog('category_validation_exception', [
                'category_id' => $excludeCategoryId,
                'name_length' => stringLength($normalizedName),
            ], 'failed', [
                'reason' => 'database_query_exception',
                'message' => $exception->getMessage(),
            ]);
        }
    }

    return [
        'valid' => $errors === [],
        'name' => $normalizedName,
        'errors' => $errors,
    ];
}

function validateTaskCategoryId(string $categoryId, array $categories, string $formAction, string $taskId = ''): array
{
    $trimmedCategoryId = trim($categoryId);
    if ($trimmedCategoryId === '') {
        writeDebugLog('task_category_validation', [
            'task_id' => $taskId,
            'category_id' => '',
            'form_action' => $formAction,
        ], 'success', [
            'result' => 'empty_category_allowed',
        ]);

        return [
            'valid' => true,
            'category_id' => '',
            'error' => '',
        ];
    }

    $category = findCategoryById($categories, $trimmedCategoryId);
    if ($category === null) {
        writeDebugLog('task_category_validation', [
            'task_id' => $taskId,
            'category_id' => $trimmedCategoryId,
            'form_action' => $formAction,
        ], 'failed', [
            'reason' => 'category_not_found',
            'database_write_blocked' => true,
        ]);

        return [
            'valid' => false,
            'category_id' => '',
            'error' => '请选择有效的任务分类。',
        ];
    }

    writeDebugLog('task_category_validation', [
        'task_id' => $taskId,
        'category_id' => $trimmedCategoryId,
        'form_action' => $formAction,
    ], 'success', [
        'category_name' => (string) $category['name'],
    ]);

    return [
        'valid' => true,
        'category_id' => $trimmedCategoryId,
        'error' => '',
    ];
}

function saveNewCategory(string $name): string
{
    $categoryId = createCategoryId();
    writeDebugLog('category_create', [
        'category_id' => $categoryId,
        'name_length' => stringLength($name),
    ], 'started');

    try {
        $pdo = getDatabaseConnection();
        $now = date('Y-m-d H:i:s');
        $sortOrderStatement = $pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM categories');
        $sortOrder = $sortOrderStatement !== false ? (int) $sortOrderStatement->fetchColumn() : 1;
        if ($sortOrderStatement !== false) {
            $sortOrderStatement->closeCursor();
        }

        $statement = $pdo->prepare(
            "INSERT INTO categories (id, name, color, sort_order, created_at, updated_at)
            VALUES (:id, :name, '#2563eb', :sort_order, :created_at, :updated_at)"
        );
        $statement->execute([
            ':id' => $categoryId,
            ':name' => $name,
            ':sort_order' => $sortOrder,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $statement->closeCursor();

        writeDebugLog('category_create', [
            'category_id' => $categoryId,
            'name_length' => stringLength($name),
        ], 'success', [
            'sort_order' => $sortOrder,
            'database_file' => basename(DB_FILE),
            'created_at' => $now,
        ]);

        return 'success';
    } catch (Throwable $exception) {
        writeDebugLog('category_create', [
            'category_id' => $categoryId,
            'name_length' => stringLength($name),
        ], 'failed', [
            'reason' => 'database_write_exception',
            'message' => $exception->getMessage(),
            'database_file' => basename(DB_FILE),
        ]);
        return 'exception';
    }
}

function saveUpdatedCategory(string $categoryId, string $name): string
{
    writeDebugLog('category_update', [
        'category_id' => $categoryId,
        'name_length' => stringLength($name),
    ], 'started');

    try {
        $pdo = getDatabaseConnection();
        $existingStatement = $pdo->prepare('SELECT id, name FROM categories WHERE id = :id LIMIT 1');
        $existingStatement->execute([':id' => $categoryId]);
        $existingCategory = $existingStatement->fetch();
        $existingStatement->closeCursor();

        if (!is_array($existingCategory)) {
            writeDebugLog('category_update', [
                'category_id' => $categoryId,
                'name_length' => stringLength($name),
            ], 'failed', [
                'reason' => $categoryId === '' ? 'empty_category_id' : 'missing_category',
            ]);
            return 'not_found';
        }

        $updatedAt = date('Y-m-d H:i:s');
        $statement = $pdo->prepare(
            "UPDATE categories
            SET name = :name,
                updated_at = :updated_at
            WHERE id = :id"
        );
        $statement->execute([
            ':name' => $name,
            ':updated_at' => $updatedAt,
            ':id' => $categoryId,
        ]);
        $statement->closeCursor();

        writeDebugLog('category_update', [
            'category_id' => $categoryId,
            'name_length' => stringLength($name),
        ], 'success', [
            'previous_name' => (string) $existingCategory['name'],
            'new_name' => $name,
            'changed' => (string) $existingCategory['name'] !== $name,
            'updated_at' => $updatedAt,
            'database_file' => basename(DB_FILE),
        ]);

        return 'success';
    } catch (Throwable $exception) {
        writeDebugLog('category_update', [
            'category_id' => $categoryId,
            'name_length' => stringLength($name),
        ], 'failed', [
            'reason' => 'database_write_exception',
            'message' => $exception->getMessage(),
            'database_file' => basename(DB_FILE),
        ]);
        return 'exception';
    }
}

function deleteCategoryById(string $categoryId): string
{
    writeDebugLog('category_delete', [
        'category_id' => $categoryId,
    ], 'started');

    try {
        $pdo = getDatabaseConnection();
        $existingStatement = $pdo->prepare('SELECT id, name FROM categories WHERE id = :id LIMIT 1');
        $existingStatement->execute([':id' => $categoryId]);
        $existingCategory = $existingStatement->fetch();
        $existingStatement->closeCursor();

        if (!is_array($existingCategory)) {
            writeDebugLog('category_delete', [
                'category_id' => $categoryId,
            ], 'failed', [
                'reason' => $categoryId === '' ? 'empty_category_id' : 'missing_category',
            ]);
            return 'not_found';
        }

        $countStatement = $pdo->prepare(
            'SELECT COUNT(*) AS total_count,
                    SUM(CASE WHEN deleted_at IS NULL THEN 1 ELSE 0 END) AS active_count
            FROM tasks
            WHERE category_id = :category_id'
        );
        $countStatement->execute([':category_id' => $categoryId]);
        $usage = $countStatement->fetch();
        $countStatement->closeCursor();
        $totalReferenceCount = is_array($usage) && isset($usage['total_count']) ? (int) $usage['total_count'] : 0;
        $activeReferenceCount = is_array($usage) && isset($usage['active_count']) ? (int) $usage['active_count'] : 0;

        if ($totalReferenceCount > 0) {
            writeDebugLog('category_reference_delete_protected', [
                'category_id' => $categoryId,
                'category_name' => (string) $existingCategory['name'],
            ], 'failed', [
                'reason' => 'category_is_referenced_by_tasks',
                'reference_count' => $totalReferenceCount,
                'active_reference_count' => $activeReferenceCount,
                'delete_blocked' => true,
                'database_integrity_preserved' => true,
            ]);
            return 'in_use';
        }

        $deleteStatement = $pdo->prepare('DELETE FROM categories WHERE id = :id');
        $deleteStatement->execute([':id' => $categoryId]);
        $changedRows = $deleteStatement->rowCount();
        $deleteStatement->closeCursor();

        writeDebugLog('category_delete', [
            'category_id' => $categoryId,
            'category_name' => (string) $existingCategory['name'],
        ], 'success', [
            'changed_rows' => $changedRows,
            'reference_count' => 0,
            'database_file' => basename(DB_FILE),
        ]);

        return 'success';
    } catch (Throwable $exception) {
        writeDebugLog('category_delete', [
            'category_id' => $categoryId,
        ], 'failed', [
            'reason' => 'database_write_exception',
            'message' => $exception->getMessage(),
            'database_file' => basename(DB_FILE),
        ]);
        return 'exception';
    }
}

function buildOrderByClause(string $sortBy, string $sortOrder): string
{
    $isAsc = $sortOrder === 'asc';
    $safeSortBy = in_array($sortBy, ALLOWED_SORT_FIELDS, true) ? $sortBy : DEFAULT_SORT_FIELD;

    switch ($safeSortBy) {
        case 'updated_at':
            $orderClause = "datetime(t.updated_at) " . ($isAsc ? 'ASC' : 'DESC');
            break;
        case 'due_at':
            $orderClause = "CASE WHEN t.due_at IS NULL OR t.due_at = '' THEN 1 ELSE 0 END ASC, datetime(t.due_at) " . ($isAsc ? 'ASC' : 'DESC');
            break;
        case 'priority':
            $orderClause = "CASE t.priority WHEN '高' THEN 1 WHEN '中' THEN 2 WHEN '低' THEN 3 ELSE 4 END " . ($isAsc ? 'ASC' : 'DESC') . ", datetime(t.created_at) DESC";
            break;
        case 'status':
            $orderClause = "CASE t.status WHEN '未开始' THEN 1 WHEN '进行中' THEN 2 WHEN '已完成' THEN 3 WHEN '已归档' THEN 4 ELSE 5 END " . ($isAsc ? 'ASC' : 'DESC') . ", datetime(t.created_at) DESC";
            break;
        case 'title':
            $orderClause = "t.title COLLATE NOCASE " . ($isAsc ? 'ASC' : 'DESC') . ", datetime(t.created_at) DESC";
            break;
        case 'created_at':
        default:
            $orderClause = "datetime(t.created_at) " . ($isAsc ? 'ASC' : 'DESC');
            break;
    }

    $orderClause .= ", t.id DESC";

    return $orderClause;
}

function normalizeTaskVisibility(string $visibility): string
{
    return in_array($visibility, [TASK_VISIBILITY_ACTIVE, TASK_VISIBILITY_ARCHIVED, TASK_VISIBILITY_ALL, TASK_VISIBILITY_TRASH], true)
        ? $visibility
        : TASK_VISIBILITY_ACTIVE;
}

function appendTaskVisibilityCondition(array &$whereConditions, string $visibility): void
{
    $normalizedVisibility = normalizeTaskVisibility($visibility);
    if ($normalizedVisibility === TASK_VISIBILITY_ACTIVE) {
        $whereConditions[] = "t.status <> '已归档' AND (t.archived_at IS NULL OR t.archived_at = '')";
    } elseif ($normalizedVisibility === TASK_VISIBILITY_ARCHIVED) {
        $whereConditions[] = "(t.status = '已归档' OR (t.archived_at IS NOT NULL AND t.archived_at <> ''))";
    }
}

function appendTaskDeletionCondition(array &$whereConditions, string $visibility): void
{
    $normalizedVisibility = normalizeTaskVisibility($visibility);
    if ($normalizedVisibility === TASK_VISIBILITY_TRASH) {
        $whereConditions[] = 't.deleted_at IS NOT NULL';
        $whereConditions[] = "t.deleted_at <> ''";
        return;
    }

    $whereConditions[] = 't.deleted_at IS NULL';
}

function loadTasksPaginated(int $page = DEFAULT_PAGE_NUMBER, int $pageSize = DEFAULT_PAGE_SIZE, string $sortBy = DEFAULT_SORT_FIELD, string $sortOrder = DEFAULT_SORT_ORDER, string $keyword = '', string $status = '', string $priority = '', string $tagId = '', string $visibility = TASK_VISIBILITY_ACTIVE): array
{
    $normalizedVisibility = normalizeTaskVisibility($visibility);
    setDatabaseErrorMessage('');
    writeDebugLog('task_list_load_paginated', [
        'database_file' => basename(DB_FILE),
        'page' => $page,
        'page_size' => $pageSize,
        'sort_by' => $sortBy,
        'sort_order' => $sortOrder,
        'keyword_length' => stringLength($keyword),
        'status' => $status,
        'priority' => $priority,
        'tag_id' => $tagId,
        'visibility' => $normalizedVisibility,
    ], 'started');

    try {
        $pdo = getDatabaseConnection();

        $whereConditions = [];
        appendTaskDeletionCondition($whereConditions, $normalizedVisibility);
        appendTaskVisibilityCondition($whereConditions, $normalizedVisibility);
        $params = [];

        if ($keyword !== '') {
            $whereConditions[] = '(t.title LIKE :keyword OR t.content LIKE :keyword)';
            $params[':keyword'] = '%' . $keyword . '%';
        }
        if ($status !== '' && isAllowedTaskStatus($status)) {
            $whereConditions[] = 't.status = :status';
            $params[':status'] = $status;
        }
        if ($priority !== '' && isAllowedTaskPriority($priority)) {
            $whereConditions[] = 't.priority = :priority';
            $params[':priority'] = $priority;
        }

        $whereClause = implode(' AND ', $whereConditions);

        $countSql = "SELECT COUNT(*) FROM tasks t WHERE " . $whereClause;
        $countStatement = $pdo->prepare($countSql);
        foreach ($params as $key => $value) {
            $countStatement->bindValue($key, $value);
        }
        $countStatement->execute();
        $totalCount = (int) $countStatement->fetchColumn();
        $countStatement->closeCursor();

        writeDebugLog('task_list_count_query', [
            'database_file' => basename(DB_FILE),
            'total_count' => $totalCount,
            'keyword_length' => stringLength($keyword),
            'status' => $status,
            'priority' => $priority,
            'visibility' => $normalizedVisibility,
        ], 'success');

        $pagination = calculatePagination($totalCount, $page, $pageSize);

        if ($tagId !== '') {
            $sql = "SELECT t.id,
                    t.title,
                    t.content,
                    t.status,
                    t.priority,
                    t.category_id,
                    c.name AS category_name,
                    t.due_at,
                    t.repeat_rule,
                    t.archived_at,
                    t.archive_previous_status,
                    t.deleted_at,
                    t.created_at,
                    t.updated_at
            FROM tasks t
            LEFT JOIN categories c ON c.id = t.category_id
            INNER JOIN task_tags tt ON tt.task_id = t.id
            WHERE " . $whereClause . " AND tt.tag_id = :tag_id
            ORDER BY " . buildOrderByClause($sortBy, $sortOrder) . "
            LIMIT :limit OFFSET :offset";
            $params[':tag_id'] = $tagId;
        } else {
            $sql = "SELECT t.id,
                    t.title,
                    t.content,
                    t.status,
                    t.priority,
                    t.category_id,
                    c.name AS category_name,
                    t.due_at,
                    t.repeat_rule,
                    t.archived_at,
                    t.archive_previous_status,
                    t.deleted_at,
                    t.created_at,
                    t.updated_at
            FROM tasks t
            LEFT JOIN categories c ON c.id = t.category_id
            WHERE " . $whereClause . "
            ORDER BY " . buildOrderByClause($sortBy, $sortOrder) . "
            LIMIT :limit OFFSET :offset";
        }

        $params[':limit'] = $pagination['page_size'];
        $params[':offset'] = $pagination['offset'];

        $statement = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value);
        }
        $statement->execute();
        $rows = $statement->fetchAll();
        $statement->closeCursor();

        $tasks = [];
        foreach ($rows as $index => $row) {
            if (is_array($row)) {
                $rawStatus = isset($row['status']) && is_string($row['status']) ? trim($row['status']) : '';
                if (!isAllowedTaskStatus($rawStatus)) {
                    writeDebugLog('task_status_read_exception', [
                        'task_id' => isset($row['id']) && is_string($row['id']) ? $row['id'] : '',
                        'raw_status' => $rawStatus,
                    ], 'failed', [
                        'reason' => 'status_outside_allowed_enum_on_read',
                        'normalized_status' => normalizeTaskStatus($rawStatus),
                        'allowed_statuses' => ALLOWED_STATUSES,
                    ]);
                }
                $rawPriority = isset($row['priority']) && is_string($row['priority']) ? trim($row['priority']) : '';
                if (!isAllowedTaskPriority($rawPriority)) {
                    writeDebugLog('task_priority_read_exception', [
                        'task_id' => isset($row['id']) && is_string($row['id']) ? $row['id'] : '',
                        'raw_priority' => $rawPriority,
                    ], 'failed', [
                        'reason' => 'priority_outside_allowed_enum_on_read',
                        'normalized_priority' => normalizeTaskPriority($rawPriority),
                        'allowed_priorities' => ALLOWED_PRIORITIES,
                    ]);
                }
                $task = normalizeTask($row, (int) $index);
                $taskId = $task['id'];
                $task['tag_ids'] = loadTagIdsForTask($pdo, $taskId);
                $task['tags'] = loadTagsForTask($pdo, $taskId);
                $taskReminder = loadTaskReminder($taskId);
                if ($taskReminder !== null) {
                    $task['remind_at'] = $taskReminder['remind_at'];
                    $task['remind_status'] = $taskReminder['status'];
                }
                $tasks[] = $task;
            }
        }

        writeDebugLog('task_list_load_paginated', [
            'database_file' => basename(DB_FILE),
            'page' => $page,
            'page_size' => $pageSize,
            'total_count' => $totalCount,
            'returned_count' => count($tasks),
            'sort_by' => $sortBy,
            'sort_order' => $sortOrder,
            'visibility' => $normalizedVisibility,
        ], 'success', [
            'pagination' => $pagination,
            'keyword_empty' => $keyword === '',
            'status_empty' => $status === '',
            'priority_empty' => $priority === '',
            'tag_id_empty' => $tagId === '',
            'visibility' => $normalizedVisibility,
        ]);

        return [
            'tasks' => $tasks,
            'pagination' => $pagination,
        ];
    } catch (Throwable $exception) {
        setDatabaseErrorMessage('数据库读取失败，系统已保留页面可用性，请稍后重试或查看日志。');
        writeDebugLog('task_list_read_paginated_exception', [
            'database_file' => basename(DB_FILE),
            'page' => $page,
            'page_size' => $pageSize,
        ], 'failed', [
            'reason' => 'database_query_exception',
            'message' => $exception->getMessage(),
        ]);
        return [
            'tasks' => [],
            'pagination' => calculatePagination(0, $page, $pageSize),
            'error' => $exception->getMessage(),
        ];
    }
}

function loadTasks(string $visibility = TASK_VISIBILITY_ALL): array
{
    $normalizedVisibility = normalizeTaskVisibility($visibility);
    setDatabaseErrorMessage('');
    writeDebugLog('task_list_load', [
        'database_file' => basename(DB_FILE),
        'visibility' => $normalizedVisibility,
    ], 'started');

    try {
        $pdo = getDatabaseConnection();
        $whereConditions = [];
        appendTaskDeletionCondition($whereConditions, $normalizedVisibility);
        appendTaskVisibilityCondition($whereConditions, $normalizedVisibility);
        $whereClause = implode(' AND ', $whereConditions);
        $statement = $pdo->query(
            "SELECT t.id,
                    t.title,
                    t.content,
                    t.status,
                    t.priority,
                    t.category_id,
                    c.name AS category_name,
                    t.due_at,
                    t.repeat_rule,
                    t.archived_at,
                    t.archive_previous_status,
                    t.deleted_at,
                    t.created_at,
                    t.updated_at
            FROM tasks t
            LEFT JOIN categories c ON c.id = t.category_id
            WHERE " . $whereClause . "
            ORDER BY CASE priority WHEN '高' THEN 1 WHEN '中' THEN 2 WHEN '低' THEN 3 ELSE 4 END ASC,
                datetime(t.created_at) DESC,
                t.id DESC"
        );
        $rows = $statement !== false ? $statement->fetchAll() : [];
        $tasks = [];
        foreach ($rows as $index => $row) {
            if (is_array($row)) {
                $rawStatus = isset($row['status']) && is_string($row['status']) ? trim($row['status']) : '';
                if (!isAllowedTaskStatus($rawStatus)) {
                    writeDebugLog('task_status_read_exception', [
                        'task_id' => isset($row['id']) && is_string($row['id']) ? $row['id'] : '',
                        'raw_status' => $rawStatus,
                    ], 'failed', [
                        'reason' => 'status_outside_allowed_enum_on_read',
                        'normalized_status' => normalizeTaskStatus($rawStatus),
                        'allowed_statuses' => ALLOWED_STATUSES,
                    ]);
                }
                $rawPriority = isset($row['priority']) && is_string($row['priority']) ? trim($row['priority']) : '';
                if (!isAllowedTaskPriority($rawPriority)) {
                    writeDebugLog('task_priority_read_exception', [
                        'task_id' => isset($row['id']) && is_string($row['id']) ? $row['id'] : '',
                        'raw_priority' => $rawPriority,
                    ], 'failed', [
                        'reason' => 'priority_outside_allowed_enum_on_read',
                        'normalized_priority' => normalizeTaskPriority($rawPriority),
                        'allowed_priorities' => ALLOWED_PRIORITIES,
                    ]);
                }
                $task = normalizeTask($row, (int) $index);
                $taskId = $task['id'];
                $task['tag_ids'] = loadTagIdsForTask($pdo, $taskId);
                $task['tags'] = loadTagsForTask($pdo, $taskId);
                $taskReminder = loadTaskReminder($taskId);
                if ($taskReminder !== null) {
                    $task['remind_at'] = $taskReminder['remind_at'];
                    $task['remind_status'] = $taskReminder['status'];
                }
                $tasks[] = $task;
            }
        }

        if (count($tasks) === 0) {
            writeDebugLog('task_list_empty_data', [
                'database_file' => basename(DB_FILE),
                'visibility' => $normalizedVisibility,
            ], 'success', [
                'reason' => 'no_database_rows',
                'task_count' => 0,
            ]);
        }

        writeDebugLog('task_list_load', [
            'database_file' => basename(DB_FILE),
            'visibility' => $normalizedVisibility,
        ], 'success', [
            'task_count' => count($tasks),
            'sort' => 'priority_then_created_at_desc',
        ]);

        return $tasks;
    } catch (Throwable $exception) {
        setDatabaseErrorMessage('数据库读取失败，系统已保留页面可用性，请稍后重试或查看日志。');
        writeDebugLog('task_list_read_exception', [
            'database_file' => basename(DB_FILE),
            'visibility' => $normalizedVisibility,
        ], 'failed', [
            'reason' => 'database_query_exception',
            'message' => $exception->getMessage(),
        ]);
        return [];
    }
}

function validateTaskInput(string $title, string $content, string $status, string $priority, string $dueAtInput, string $remindAtInput, string $categoryId, array $categories, string $formAction, string $taskId = '', string $repeatRule = ''): array
{
    $errors = [];
    $trimmedTitle = trim($title);
    $trimmedContent = trim($content);

    if ($trimmedTitle === '') {
        $errors['title'] = '任务标题不能为空。';
    } elseif (stringLength($trimmedTitle) > MAX_TITLE_LENGTH) {
        $errors['title'] = '任务标题不能超过 ' . MAX_TITLE_LENGTH . ' 个字符。';
    }

    if (stringLength($trimmedContent) > MAX_CONTENT_LENGTH) {
        $errors['content'] = '任务内容不能超过 ' . MAX_CONTENT_LENGTH . ' 个字符。';
    }

    if (!isAllowedTaskStatus($status)) {
        $errors['status'] = '请选择有效的任务状态。';
    }

    if (!isAllowedTaskPriority($priority)) {
        $errors['priority'] = '请选择有效的任务优先级。';
    }

    $dueAtValidation = validateDueAtInput($dueAtInput);
    if (!$dueAtValidation['valid']) {
        $errors['due_at'] = $dueAtValidation['error'];
    }

    $dueAtForRemindValidation = $dueAtValidation['normalized'] ?? '';
    $remindAtValidation = validateRemindAtInput($remindAtInput, $dueAtForRemindValidation);
    if (!$remindAtValidation['valid']) {
        $errors['remind_at'] = $remindAtValidation['error'];
    }

    $categoryValidation = validateTaskCategoryId($categoryId, $categories, $formAction, $taskId);
    if (!$categoryValidation['valid']) {
        $errors['category_id'] = $categoryValidation['error'];
    }

    $dueAtForRepeatValidation = $dueAtValidation['normalized'] ?? '';
    $repeatRuleValidation = validateRepeatRuleInput($repeatRule, $dueAtForRepeatValidation);
    if (!$repeatRuleValidation['valid']) {
        $errors['repeat_rule'] = $repeatRuleValidation['error'];
    }

    return $errors;
}

function buildStatusCounts(array $tasks): array
{
    $counts = [];
    foreach (ALLOWED_STATUSES as $status) {
        $counts[$status] = 0;
    }

    foreach ($tasks as $task) {
        $status = isset($task['status']) && is_string($task['status']) ? normalizeTaskStatus($task['status']) : '未开始';
        if (!isset($counts[$status])) {
            $counts[$status] = 0;
        }
        $counts[$status]++;
    }

    return $counts;
}

function buildPriorityCountsTemplate(): array
{
    $counts = [];
    foreach (ALLOWED_PRIORITIES as $priority) {
        $counts[$priority] = 0;
    }

    return $counts;
}

function buildEmptyTaskDashboardStats(string $errorMessage = ''): array
{
    return [
        'metrics' => [
            'total' => 0,
            'active' => 0,
            'completed' => 0,
            'in_progress' => 0,
            'overdue' => 0,
            'archived' => 0,
        ],
        'status_counts' => buildStatusCounts([]),
        'priority_counts' => buildPriorityCountsTemplate(),
        'upcoming_tasks' => [],
        'upcoming_days' => DASHBOARD_UPCOMING_DAYS,
        'generated_at' => date('Y-m-d H:i:s'),
        'has_tasks' => false,
        'error' => $errorMessage,
    ];
}

function loadTaskDashboardStats(): array
{
    $now = date('Y-m-d H:i:s');
    $upcomingEnd = (new DateTimeImmutable($now))->modify('+' . DASHBOARD_UPCOMING_DAYS . ' days')->format('Y-m-d H:i:s');
    writeDebugLog('task_dashboard_stats_query', [
        'database_file' => basename(DB_FILE),
        'current_time' => $now,
        'upcoming_start' => $now,
        'upcoming_end' => $upcomingEnd,
        'upcoming_limit' => DASHBOARD_UPCOMING_LIMIT,
    ], 'started');

    try {
        $pdo = getDatabaseConnection();
        $stats = buildEmptyTaskDashboardStats();
        $stats['generated_at'] = $now;

        $aggregateStatement = $pdo->prepare(
            "SELECT
                COUNT(*) AS total_count,
                SUM(CASE WHEN status <> '已归档' AND (archived_at IS NULL OR archived_at = '') THEN 1 ELSE 0 END) AS active_count,
                SUM(CASE WHEN status = '已完成' THEN 1 ELSE 0 END) AS completed_count,
                SUM(CASE WHEN status = '进行中' AND (archived_at IS NULL OR archived_at = '') THEN 1 ELSE 0 END) AS in_progress_count,
                SUM(CASE WHEN (status = '已归档' OR (archived_at IS NOT NULL AND archived_at <> '')) THEN 1 ELSE 0 END) AS archived_count,
                SUM(CASE
                    WHEN status <> '已完成'
                        AND status <> '已归档'
                        AND (archived_at IS NULL OR archived_at = '')
                        AND due_at IS NOT NULL
                        AND due_at <> ''
                        AND datetime(due_at) < datetime(:now)
                    THEN 1 ELSE 0
                END) AS overdue_count
            FROM tasks
            WHERE deleted_at IS NULL"
        );
        $aggregateStatement->execute([':now' => $now]);
        $aggregateRow = $aggregateStatement->fetch();
        $aggregateStatement->closeCursor();

        if (is_array($aggregateRow)) {
            $stats['metrics'] = [
                'total' => (int) ($aggregateRow['total_count'] ?? 0),
                'active' => (int) ($aggregateRow['active_count'] ?? 0),
                'completed' => (int) ($aggregateRow['completed_count'] ?? 0),
                'in_progress' => (int) ($aggregateRow['in_progress_count'] ?? 0),
                'overdue' => (int) ($aggregateRow['overdue_count'] ?? 0),
                'archived' => (int) ($aggregateRow['archived_count'] ?? 0),
            ];
        }

        writeDebugLog('task_dashboard_metric_calculate', [
            'database_file' => basename(DB_FILE),
            'current_time' => $now,
        ], 'success', [
            'metrics' => $stats['metrics'],
        ]);

        $statusStatement = $pdo->query(
            "SELECT status, COUNT(*) AS status_count
            FROM tasks
            WHERE deleted_at IS NULL
            GROUP BY status"
        );
        $statusRows = $statusStatement !== false ? $statusStatement->fetchAll() : [];
        foreach ($statusRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rawStatus = isset($row['status']) && is_string($row['status']) ? trim($row['status']) : '';
            $normalizedStatus = normalizeTaskStatus($rawStatus);
            if (!isset($stats['status_counts'][$normalizedStatus])) {
                $stats['status_counts'][$normalizedStatus] = 0;
            }
            $stats['status_counts'][$normalizedStatus] += (int) ($row['status_count'] ?? 0);
        }

        $priorityStatement = $pdo->query(
            "SELECT priority, COUNT(*) AS priority_count
            FROM tasks
            WHERE deleted_at IS NULL
            GROUP BY priority"
        );
        $priorityRows = $priorityStatement !== false ? $priorityStatement->fetchAll() : [];
        foreach ($priorityRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rawPriority = isset($row['priority']) && is_string($row['priority']) ? trim($row['priority']) : '';
            $normalizedPriority = normalizeTaskPriority($rawPriority);
            if (!isset($stats['priority_counts'][$normalizedPriority])) {
                $stats['priority_counts'][$normalizedPriority] = 0;
            }
            $stats['priority_counts'][$normalizedPriority] += (int) ($row['priority_count'] ?? 0);
        }

        $upcomingStatement = $pdo->prepare(
            "SELECT id, title, status, priority, due_at
            FROM tasks
            WHERE deleted_at IS NULL
                AND status <> '已完成'
                AND status <> '已归档'
                AND (archived_at IS NULL OR archived_at = '')
                AND due_at IS NOT NULL
                AND due_at <> ''
                AND datetime(due_at) >= datetime(:now)
                AND datetime(due_at) <= datetime(:upcoming_end)
            ORDER BY datetime(due_at) ASC,
                CASE priority WHEN '高' THEN 1 WHEN '中' THEN 2 WHEN '低' THEN 3 ELSE 4 END ASC,
                datetime(created_at) DESC
            LIMIT :limit"
        );
        $upcomingStatement->bindValue(':now', $now);
        $upcomingStatement->bindValue(':upcoming_end', $upcomingEnd);
        $upcomingStatement->bindValue(':limit', DASHBOARD_UPCOMING_LIMIT, PDO::PARAM_INT);
        $upcomingStatement->execute();
        $upcomingRows = $upcomingStatement->fetchAll();
        $upcomingStatement->closeCursor();

        foreach ($upcomingRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $stats['upcoming_tasks'][] = [
                'id' => isset($row['id']) && is_string($row['id']) ? trim($row['id']) : '',
                'title' => isset($row['title']) && is_string($row['title']) ? trim($row['title']) : '',
                'status' => isset($row['status']) && is_string($row['status']) ? normalizeTaskStatus($row['status']) : '未开始',
                'priority' => isset($row['priority']) && is_string($row['priority']) ? normalizeTaskPriority($row['priority']) : DEFAULT_TASK_PRIORITY,
                'due_at' => normalizeStoredDueAt($row['due_at'] ?? ''),
            ];
        }

        $stats['has_tasks'] = $stats['metrics']['total'] > 0;
        if (!$stats['has_tasks']) {
            writeDebugLog('task_dashboard_empty_data', [
                'database_file' => basename(DB_FILE),
            ], 'success', [
                'reason' => 'no_non_deleted_tasks',
                'metrics' => $stats['metrics'],
                'status_counts' => $stats['status_counts'],
                'priority_counts' => $stats['priority_counts'],
            ]);
        }

        writeDebugLog('task_dashboard_stats_query', [
            'database_file' => basename(DB_FILE),
            'current_time' => $now,
            'upcoming_end' => $upcomingEnd,
        ], 'success', [
            'metrics' => $stats['metrics'],
            'status_counts' => $stats['status_counts'],
            'priority_counts' => $stats['priority_counts'],
            'upcoming_count' => count($stats['upcoming_tasks']),
            'has_tasks' => $stats['has_tasks'],
        ]);

        return $stats;
    } catch (Throwable $exception) {
        setDatabaseErrorMessage('统计看板读取失败，页面其他功能仍可继续使用，请查看日志排查。');
        writeDebugLog('task_dashboard_stats_exception', [
            'database_file' => basename(DB_FILE),
            'current_time' => $now,
            'upcoming_end' => $upcomingEnd,
        ], 'failed', [
            'reason' => 'dashboard_statistics_exception',
            'message' => $exception->getMessage(),
            'fallback_metrics' => buildEmptyTaskDashboardStats($exception->getMessage())['metrics'],
        ]);
        return buildEmptyTaskDashboardStats($exception->getMessage());
    }
}

function normalizeCalendarMonth(string $monthInput): array
{
    $rawMonth = trim($monthInput);
    $usedFallback = false;
    $monthDate = null;

    if ($rawMonth !== '' && preg_match('/^\d{4}-\d{2}$/', $rawMonth) === 1) {
        $candidate = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $rawMonth . '-01 00:00:00');
        $dateErrors = DateTimeImmutable::getLastErrors();
        if ($candidate instanceof DateTimeImmutable && $dateErrors === false) {
            $monthDate = $candidate;
        }
    }

    if (!$monthDate instanceof DateTimeImmutable) {
        $usedFallback = true;
        $monthDate = new DateTimeImmutable(date('Y-m-01 00:00:00'));
    }

    $monthStart = $monthDate->modify('first day of this month')->setTime(0, 0, 0);
    $monthEnd = $monthDate->modify('last day of this month')->setTime(23, 59, 59);

    writeDebugLog('task_calendar_month_normalize', [
        'submitted_month' => $rawMonth,
    ], $usedFallback && $rawMonth !== '' ? 'failed' : 'success', [
        'normalized_month' => $monthStart->format('Y-m'),
        'month_start' => $monthStart->format('Y-m-d H:i:s'),
        'month_end' => $monthEnd->format('Y-m-d H:i:s'),
        'used_fallback' => $usedFallback,
    ]);

    return [
        'month' => $monthStart->format('Y-m'),
        'label' => $monthStart->format('Y年m月'),
        'start_date' => $monthStart->format('Y-m-d'),
        'end_date' => $monthEnd->format('Y-m-d'),
        'start_datetime' => $monthStart->format('Y-m-d H:i:s'),
        'end_datetime' => $monthEnd->format('Y-m-d H:i:s'),
        'prev_month' => $monthStart->modify('-1 month')->format('Y-m'),
        'next_month' => $monthStart->modify('+1 month')->format('Y-m'),
        'days_in_month' => (int) $monthStart->format('t'),
        'first_weekday' => (int) $monthStart->format('N'),
        'used_fallback' => $usedFallback,
        'raw_month' => $rawMonth,
    ];
}

function normalizeCalendarDate(string $dateInput, array $monthState): array
{
    $rawDate = trim($dateInput);
    $selectedDate = null;
    $usedFallback = false;
    $reason = 'submitted_date_valid';

    if ($rawDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate) === 1) {
        $candidate = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $rawDate . ' 00:00:00');
        $dateErrors = DateTimeImmutable::getLastErrors();
        if ($candidate instanceof DateTimeImmutable && $dateErrors === false && $candidate->format('Y-m-d') === $rawDate) {
            $selectedDate = $candidate;
        }
    }

    if (!$selectedDate instanceof DateTimeImmutable) {
        $usedFallback = true;
        $today = new DateTimeImmutable(date('Y-m-d 00:00:00'));
        $month = isset($monthState['month']) && is_string($monthState['month']) ? $monthState['month'] : date('Y-m');
        if ($today->format('Y-m') === $month) {
            $selectedDate = $today;
            $reason = $rawDate === '' ? 'empty_date_default_today' : 'invalid_date_default_today';
        } else {
            $selectedDate = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $monthState['start_date'] . ' 00:00:00');
            $reason = $rawDate === '' ? 'empty_date_default_month_start' : 'invalid_date_default_month_start';
        }
    }

    if ($selectedDate instanceof DateTimeImmutable && $selectedDate->format('Y-m') !== (string) $monthState['month']) {
        $usedFallback = true;
        $selectedDate = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $monthState['start_date'] . ' 00:00:00');
        $reason = 'date_outside_visible_month_default_month_start';
    }

    if (!$selectedDate instanceof DateTimeImmutable) {
        $selectedDate = new DateTimeImmutable(date('Y-m-d 00:00:00'));
        $usedFallback = true;
        $reason = 'date_fallback_exception_guard';
    }

    writeDebugLog('task_calendar_date_normalize', [
        'submitted_date' => $rawDate,
        'visible_month' => (string) $monthState['month'],
    ], $usedFallback && $rawDate !== '' ? 'failed' : 'success', [
        'selected_date' => $selectedDate->format('Y-m-d'),
        'used_fallback' => $usedFallback,
        'reason' => $reason,
    ]);

    return [
        'date' => $selectedDate->format('Y-m-d'),
        'label' => $selectedDate->format('Y年m月d日'),
        'raw_date' => $rawDate,
        'used_fallback' => $usedFallback,
        'reason' => $reason,
    ];
}

function buildEmptyCalendarView(string $errorMessage = ''): array
{
    $monthState = normalizeCalendarMonth('');
    $dateState = normalizeCalendarDate('', $monthState);

    return [
        'month' => $monthState,
        'selected_date' => $dateState,
        'weeks' => [],
        'day_events' => [],
        'selected_events' => [],
        'total_events' => 0,
        'event_days' => 0,
        'generated_at' => date('Y-m-d H:i:s'),
        'error' => $errorMessage,
    ];
}

function buildCalendarWeeks(array $monthState, array $dayEvents, string $selectedDate): array
{
    $weeks = [];
    $currentWeek = [];
    $today = date('Y-m-d');
    $firstWeekday = isset($monthState['first_weekday']) ? max(1, min(7, (int) $monthState['first_weekday'])) : 1;
    $daysInMonth = isset($monthState['days_in_month']) ? max(1, min(31, (int) $monthState['days_in_month'])) : 31;
    $month = isset($monthState['month']) && is_string($monthState['month']) ? $monthState['month'] : date('Y-m');

    for ($blank = 1; $blank < $firstWeekday; $blank++) {
        $currentWeek[] = [
            'date' => '',
            'day_number' => '',
            'is_current_month' => false,
            'is_today' => false,
            'is_selected' => false,
            'event_count' => 0,
            'due_count' => 0,
            'remind_count' => 0,
            'summaries' => [],
        ];
    }

    for ($day = 1; $day <= $daysInMonth; $day++) {
        $date = sprintf('%s-%02d', $month, $day);
        $eventsForDate = $dayEvents[$date] ?? [
            'count' => 0,
            'due_count' => 0,
            'remind_count' => 0,
            'summaries' => [],
        ];
        $currentWeek[] = [
            'date' => $date,
            'day_number' => (string) $day,
            'is_current_month' => true,
            'is_today' => $date === $today,
            'is_selected' => $date === $selectedDate,
            'event_count' => (int) ($eventsForDate['count'] ?? 0),
            'due_count' => (int) ($eventsForDate['due_count'] ?? 0),
            'remind_count' => (int) ($eventsForDate['remind_count'] ?? 0),
            'summaries' => isset($eventsForDate['summaries']) && is_array($eventsForDate['summaries']) ? $eventsForDate['summaries'] : [],
        ];

        if (count($currentWeek) === 7) {
            $weeks[] = $currentWeek;
            $currentWeek = [];
        }
    }

    if ($currentWeek !== []) {
        while (count($currentWeek) < 7) {
            $currentWeek[] = [
                'date' => '',
                'day_number' => '',
                'is_current_month' => false,
                'is_today' => false,
                'is_selected' => false,
                'event_count' => 0,
                'due_count' => 0,
                'remind_count' => 0,
                'summaries' => [],
            ];
        }
        $weeks[] = $currentWeek;
    }

    return $weeks;
}

function loadTaskCalendarView(string $monthInput, string $dateInput): array
{
    $monthState = normalizeCalendarMonth($monthInput);
    $dateState = normalizeCalendarDate($dateInput, $monthState);

    if ($monthInput !== '') {
        writeDebugLog('task_calendar_month_switch', [
            'submitted_month' => trim($monthInput),
            'selected_date' => $dateState['date'],
        ], 'success', [
            'visible_month' => $monthState['month'],
            'prev_month' => $monthState['prev_month'],
            'next_month' => $monthState['next_month'],
            'used_fallback' => $monthState['used_fallback'],
        ]);
    }

    writeDebugLog('task_calendar_load', [
        'database_file' => basename(DB_FILE),
        'visible_month' => $monthState['month'],
        'month_start' => $monthState['start_date'],
        'month_end' => $monthState['end_date'],
        'selected_date' => $dateState['date'],
    ], 'started');

    try {
        $pdo = getDatabaseConnection();
        $statement = $pdo->prepare(
            "SELECT *
            FROM (
                SELECT
                    t.id,
                    t.title,
                    t.content,
                    t.status,
                    t.priority,
                    t.category_id,
                    c.name AS category_name,
                    t.due_at,
                    r.remind_at AS remind_at,
                    t.repeat_rule,
                    t.archived_at,
                    t.archive_previous_status,
                    t.deleted_at,
                    t.created_at,
                    t.updated_at,
                    'due' AS calendar_type,
                    t.due_at AS calendar_at
                FROM tasks t
                LEFT JOIN categories c ON c.id = t.category_id
                LEFT JOIN reminders r ON r.task_id = t.id
                WHERE t.deleted_at IS NULL
                    AND t.due_at IS NOT NULL
                    AND t.due_at <> ''
                    AND date(t.due_at) BETWEEN date(:month_start_due) AND date(:month_end_due)
                UNION ALL
                SELECT
                    t.id,
                    t.title,
                    t.content,
                    t.status,
                    t.priority,
                    t.category_id,
                    c.name AS category_name,
                    t.due_at,
                    r.remind_at AS remind_at,
                    t.repeat_rule,
                    t.archived_at,
                    t.archive_previous_status,
                    t.deleted_at,
                    t.created_at,
                    t.updated_at,
                    'remind' AS calendar_type,
                    r.remind_at AS calendar_at
                FROM reminders r
                INNER JOIN tasks t ON t.id = r.task_id
                LEFT JOIN categories c ON c.id = t.category_id
                WHERE t.deleted_at IS NULL
                    AND r.remind_at IS NOT NULL
                    AND r.remind_at <> ''
                    AND date(r.remind_at) BETWEEN date(:month_start_remind) AND date(:month_end_remind)
            ) calendar_events
            ORDER BY date(calendar_at) ASC,
                datetime(calendar_at) ASC,
                CASE priority WHEN '高' THEN 1 WHEN '中' THEN 2 WHEN '低' THEN 3 ELSE 4 END ASC,
                datetime(created_at) DESC,
                id ASC"
        );
        $statement->execute([
            ':month_start_due' => $monthState['start_date'],
            ':month_end_due' => $monthState['end_date'],
            ':month_start_remind' => $monthState['start_date'],
            ':month_end_remind' => $monthState['end_date'],
        ]);
        $rows = $statement->fetchAll();
        $statement->closeCursor();

        $dayEvents = [];
        $selectedEvents = [];
        $totalEvents = 0;

        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $calendarType = isset($row['calendar_type']) && $row['calendar_type'] === 'remind' ? 'remind' : 'due';
            $calendarAt = $calendarType === 'remind'
                ? normalizeStoredRemindAt($row['calendar_at'] ?? '')
                : normalizeStoredDueAt($row['calendar_at'] ?? '');
            if ($calendarAt === '') {
                writeDebugLog('task_calendar_event_skip', [
                    'task_id' => isset($row['id']) && is_string($row['id']) ? $row['id'] : '',
                    'calendar_type' => $calendarType,
                    'calendar_at' => isset($row['calendar_at']) && is_string($row['calendar_at']) ? $row['calendar_at'] : '',
                ], 'failed', [
                    'reason' => 'calendar_at_unparseable',
                    'visible_month' => $monthState['month'],
                ]);
                continue;
            }

            $eventDate = substr($calendarAt, 0, 10);
            if ($eventDate < $monthState['start_date'] || $eventDate > $monthState['end_date']) {
                continue;
            }

            if (!isset($dayEvents[$eventDate])) {
                $dayEvents[$eventDate] = [
                    'count' => 0,
                    'due_count' => 0,
                    'remind_count' => 0,
                    'summaries' => [],
                ];
            }

            $task = normalizeTask($row, (int) $index);
            $task['remind_at'] = normalizeStoredRemindAt($row['remind_at'] ?? '');
            $event = [
                'task' => $task,
                'type' => $calendarType,
                'type_label' => $calendarType === 'remind' ? '提醒' : '截止',
                'event_at' => $calendarAt,
                'event_date' => $eventDate,
                'event_time' => formatDateTime($calendarAt),
            ];

            $dayEvents[$eventDate]['count']++;
            if ($calendarType === 'remind') {
                $dayEvents[$eventDate]['remind_count']++;
            } else {
                $dayEvents[$eventDate]['due_count']++;
            }
            if (count($dayEvents[$eventDate]['summaries']) < CALENDAR_SUMMARY_LIMIT) {
                $dayEvents[$eventDate]['summaries'][] = [
                    'title' => (string) $task['title'],
                    'type' => $calendarType,
                    'type_label' => $event['type_label'],
                    'status' => normalizeTaskStatus((string) $task['status']),
                ];
            }
            if ($eventDate === $dateState['date']) {
                $selectedEvents[] = $event;
            }
            $totalEvents++;
        }

        $weeks = buildCalendarWeeks($monthState, $dayEvents, $dateState['date']);

        writeDebugLog('task_calendar_date_task_query', [
            'selected_date' => $dateState['date'],
            'visible_month' => $monthState['month'],
        ], 'success', [
            'result_count' => count($selectedEvents),
            'due_count' => isset($dayEvents[$dateState['date']]) ? (int) $dayEvents[$dateState['date']]['due_count'] : 0,
            'remind_count' => isset($dayEvents[$dateState['date']]) ? (int) $dayEvents[$dateState['date']]['remind_count'] : 0,
        ]);

        writeDebugLog('task_calendar_load', [
            'database_file' => basename(DB_FILE),
            'visible_month' => $monthState['month'],
            'selected_date' => $dateState['date'],
        ], 'success', [
            'event_count' => $totalEvents,
            'event_day_count' => count($dayEvents),
            'selected_event_count' => count($selectedEvents),
            'week_count' => count($weeks),
        ]);

        return [
            'month' => $monthState,
            'selected_date' => $dateState,
            'weeks' => $weeks,
            'day_events' => $dayEvents,
            'selected_events' => $selectedEvents,
            'total_events' => $totalEvents,
            'event_days' => count($dayEvents),
            'generated_at' => date('Y-m-d H:i:s'),
            'error' => '',
        ];
    } catch (Throwable $exception) {
        setDatabaseErrorMessage('日历视图读取失败，页面其他功能仍可继续使用，请查看日志排查。');
        writeDebugLog('task_calendar_exception', [
            'database_file' => basename(DB_FILE),
            'visible_month' => $monthState['month'],
            'selected_date' => $dateState['date'],
        ], 'failed', [
            'reason' => 'calendar_query_exception',
            'message' => $exception->getMessage(),
            'month_start' => $monthState['start_date'],
            'month_end' => $monthState['end_date'],
        ]);

        return [
            'month' => $monthState,
            'selected_date' => $dateState,
            'weeks' => buildCalendarWeeks($monthState, [], $dateState['date']),
            'day_events' => [],
            'selected_events' => [],
            'total_events' => 0,
            'event_days' => 0,
            'generated_at' => date('Y-m-d H:i:s'),
            'error' => $exception->getMessage(),
        ];
    }
}

function findTaskById(array $tasks, string $taskId): ?array
{
    foreach ($tasks as $task) {
        if (($task['id'] ?? '') === $taskId) {
            return $task;
        }
    }

    return null;
}

function loadSubtasksForTask(PDO $pdo, string $taskId): array
{
    writeDebugLog('task_subtasks_load', [
        'task_id' => $taskId,
    ], 'started');

    try {
        $statement = $pdo->prepare(
            "SELECT id, task_id, title, is_completed, sort_order, created_at, updated_at
            FROM subtasks
            WHERE task_id = :task_id
            ORDER BY sort_order ASC, created_at ASC"
        );
        $statement->execute([':task_id' => $taskId]);
        $rows = $statement->fetchAll();
        $statement->closeCursor();

        $subtasks = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $subtasks[] = [
                'id' => isset($row['id']) && is_string($row['id']) ? trim($row['id']) : '',
                'task_id' => isset($row['task_id']) && is_string($row['task_id']) ? trim($row['task_id']) : '',
                'title' => isset($row['title']) && is_string($row['title']) ? trim($row['title']) : '',
                'is_completed' => isset($row['is_completed']) ? (int) $row['is_completed'] : 0,
                'sort_order' => isset($row['sort_order']) ? (int) $row['sort_order'] : 0,
                'created_at' => isset($row['created_at']) && is_string($row['created_at']) ? trim($row['created_at']) : '',
                'updated_at' => isset($row['updated_at']) && is_string($row['updated_at']) ? trim($row['updated_at']) : '',
            ];
        }

        writeDebugLog('task_subtasks_load', [
            'task_id' => $taskId,
        ], 'success', [
            'subtask_count' => count($subtasks),
        ]);

        return $subtasks;
    } catch (Throwable $exception) {
        writeDebugLog('task_subtasks_load', [
            'task_id' => $taskId,
        ], 'failed', [
            'reason' => 'database_query_exception',
            'message' => $exception->getMessage(),
        ]);
        return [];
    }
}

const MAX_SUBTASK_TITLE_LENGTH = 200;

function createSubtaskId(): string
{
    try {
        return 'subtask-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
    } catch (Throwable $exception) {
        return 'subtask-' . date('YmdHis') . '-' . str_replace('.', '', uniqid('', true));
    }
}

function validateSubtaskInput(string $title, string $parentTaskId, array $tasks): array
{
    $errors = [];
    $normalizedTitle = trim($title);

    if ($normalizedTitle === '') {
        $errors['title'] = '子任务标题不能为空。';
        writeDebugLog('subtask_validation', [
            'parent_task_id' => $parentTaskId,
            'title_length' => 0,
        ], 'failed', [
            'reason' => 'empty_title',
            'database_write_blocked' => true,
        ]);
    } elseif (stringLength($normalizedTitle) > MAX_SUBTASK_TITLE_LENGTH) {
        $errors['title'] = '子任务标题不能超过 ' . MAX_SUBTASK_TITLE_LENGTH . ' 个字符。';
        writeDebugLog('subtask_validation', [
            'parent_task_id' => $parentTaskId,
            'title_length' => stringLength($normalizedTitle),
        ], 'failed', [
            'reason' => 'title_too_long',
            'max_length' => MAX_SUBTASK_TITLE_LENGTH,
            'database_write_blocked' => true,
        ]);
    }

    if ($parentTaskId === '') {
        $errors['parent_task'] = '父任务不存在，无法创建子任务。';
        writeDebugLog('subtask_validation', [
            'parent_task_id' => '',
            'title_length' => stringLength($normalizedTitle),
        ], 'failed', [
            'reason' => 'empty_parent_task_id',
            'database_write_blocked' => true,
        ]);
    } else {
        $parentTask = findTaskById($tasks, $parentTaskId);
        if ($parentTask === null) {
            $errors['parent_task'] = '父任务不存在，无法创建子任务。';
            writeDebugLog('subtask_validation', [
                'parent_task_id' => $parentTaskId,
                'title_length' => stringLength($normalizedTitle),
            ], 'failed', [
                'reason' => 'parent_task_not_found',
                'database_write_blocked' => true,
            ]);
        }
    }

    return [
        'valid' => $errors === [],
        'title' => $normalizedTitle,
        'errors' => $errors,
    ];
}

function saveNewSubtask(string $taskId, string $title): string
{
    $subtaskId = createSubtaskId();
    writeDebugLog('subtask_create', [
        'subtask_id' => $subtaskId,
        'task_id' => $taskId,
        'title_length' => stringLength($title),
    ], 'started');

    try {
        $pdo = getDatabaseConnection();
        $now = date('Y-m-d H:i:s');

        $sortOrderStatement = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM subtasks WHERE task_id = :task_id');
        $sortOrderStatement->execute([':task_id' => $taskId]);
        $sortOrder = $sortOrderStatement->fetchColumn();
        if ($sortOrderStatement !== false) {
            $sortOrderStatement->closeCursor();
        }
        if (!is_int($sortOrder) && !is_numeric($sortOrder)) {
            $sortOrder = 1;
        } else {
            $sortOrder = (int) $sortOrder;
        }

        $statement = $pdo->prepare(
            "INSERT INTO subtasks (id, task_id, title, is_completed, sort_order, created_at, updated_at)
            VALUES (:id, :task_id, :title, 0, :sort_order, :created_at, :updated_at)"
        );
        $statement->execute([
            ':id' => $subtaskId,
            ':task_id' => $taskId,
            ':title' => $title,
            ':sort_order' => $sortOrder,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $statement->closeCursor();

        writeDebugLog('subtask_create', [
            'subtask_id' => $subtaskId,
            'task_id' => $taskId,
            'title_length' => stringLength($title),
        ], 'success', [
            'created_at' => $now,
            'sort_order' => $sortOrder,
        ]);

        return 'success';
    } catch (Throwable $exception) {
        writeDebugLog('subtask_create', [
            'subtask_id' => $subtaskId,
            'task_id' => $taskId,
            'title_length' => stringLength($title),
        ], 'failed', [
            'reason' => 'database_write_exception',
            'message' => $exception->getMessage(),
        ]);
        return 'exception';
    }
}

function saveUpdatedSubtask(string $subtaskId, string $title): string
{
    writeDebugLog('subtask_update', [
        'subtask_id' => $subtaskId,
        'title_length' => stringLength($title),
    ], 'started');

    try {
        $pdo = getDatabaseConnection();

        $existingStatement = $pdo->prepare('SELECT id, task_id, title, is_completed FROM subtasks WHERE id = :id LIMIT 1');
        $existingStatement->execute([':id' => $subtaskId]);
        $existingSubtask = $existingStatement->fetch();
        $existingStatement->closeCursor();

        if (!is_array($existingSubtask)) {
            writeDebugLog('subtask_update', [
                'subtask_id' => $subtaskId,
                'title_length' => stringLength($title),
            ], 'failed', [
                'reason' => $subtaskId === '' ? 'empty_subtask_id' : 'missing_subtask',
            ]);
            return 'not_found';
        }

        $updatedAt = date('Y-m-d H:i:s');
        $statement = $pdo->prepare(
            "UPDATE subtasks
            SET title = :title,
                updated_at = :updated_at
            WHERE id = :id"
        );
        $statement->execute([
            ':title' => $title,
            ':updated_at' => $updatedAt,
            ':id' => $subtaskId,
        ]);
        $statement->closeCursor();

        writeDebugLog('subtask_update', [
            'subtask_id' => $subtaskId,
            'title_length' => stringLength($title),
        ], 'success', [
            'previous_title' => (string) $existingSubtask['title'],
            'new_title' => $title,
            'updated_at' => $updatedAt,
        ]);

        return 'success';
    } catch (Throwable $exception) {
        writeDebugLog('subtask_update', [
            'subtask_id' => $subtaskId,
            'title_length' => stringLength($title),
        ], 'failed', [
            'reason' => 'database_write_exception',
            'message' => $exception->getMessage(),
        ]);
        return 'exception';
    }
}

function toggleSubtaskCompletion(string $subtaskId): string
{
    writeDebugLog('subtask_toggle', [
        'subtask_id' => $subtaskId,
    ], 'started');

    try {
        $pdo = getDatabaseConnection();

        $existingStatement = $pdo->prepare('SELECT id, task_id, title, is_completed FROM subtasks WHERE id = :id LIMIT 1');
        $existingStatement->execute([':id' => $subtaskId]);
        $existingSubtask = $existingStatement->fetch();
        $existingStatement->closeCursor();

        if (!is_array($existingSubtask)) {
            writeDebugLog('subtask_toggle', [
                'subtask_id' => $subtaskId,
            ], 'failed', [
                'reason' => $subtaskId === '' ? 'empty_subtask_id' : 'missing_subtask',
            ]);
            return 'not_found';
        }

        $currentCompleted = (int) ($existingSubtask['is_completed'] ?? 0);
        $newCompleted = $currentCompleted === 1 ? 0 : 1;
        $updatedAt = date('Y-m-d H:i:s');

        $statement = $pdo->prepare(
            "UPDATE subtasks
            SET is_completed = :is_completed,
                updated_at = :updated_at
            WHERE id = :id"
        );
        $statement->execute([
            ':is_completed' => $newCompleted,
            ':updated_at' => $updatedAt,
            ':id' => $subtaskId,
        ]);
        $statement->closeCursor();

        writeDebugLog('subtask_toggle', [
            'subtask_id' => $subtaskId,
        ], 'success', [
            'previous_completed' => $currentCompleted,
            'new_completed' => $newCompleted,
            'updated_at' => $updatedAt,
        ]);

        return 'success';
    } catch (Throwable $exception) {
        writeDebugLog('subtask_toggle', [
            'subtask_id' => $subtaskId,
        ], 'failed', [
            'reason' => 'database_write_exception',
            'message' => $exception->getMessage(),
        ]);
        return 'exception';
    }
}

function deleteSubtaskById(string $subtaskId): string
{
    writeDebugLog('subtask_delete', [
        'subtask_id' => $subtaskId,
    ], 'started');

    try {
        $pdo = getDatabaseConnection();

        $existingStatement = $pdo->prepare('SELECT id, task_id, title, is_completed FROM subtasks WHERE id = :id LIMIT 1');
        $existingStatement->execute([':id' => $subtaskId]);
        $existingSubtask = $existingStatement->fetch();
        $existingStatement->closeCursor();

        if (!is_array($existingSubtask)) {
            writeDebugLog('subtask_delete', [
                'subtask_id' => $subtaskId,
            ], 'failed', [
                'reason' => $subtaskId === '' ? 'empty_subtask_id' : 'missing_subtask',
            ]);
            return 'not_found';
        }

        $deleteStatement = $pdo->prepare('DELETE FROM subtasks WHERE id = :id');
        $deleteStatement->execute([':id' => $subtaskId]);
        $changedRows = $deleteStatement->rowCount();
        $deleteStatement->closeCursor();

        writeDebugLog('subtask_delete', [
            'subtask_id' => $subtaskId,
            'task_id' => (string) ($existingSubtask['task_id'] ?? ''),
        ], 'success', [
            'changed_rows' => $changedRows,
        ]);

        return 'success';
    } catch (Throwable $exception) {
        writeDebugLog('subtask_delete', [
            'subtask_id' => $subtaskId,
        ], 'failed', [
            'reason' => 'database_write_exception',
            'message' => $exception->getMessage(),
        ]);
        return 'exception';
    }
}

function findSubtaskById(PDO $pdo, string $subtaskId): ?array
{
    $statement = $pdo->prepare('SELECT id, task_id, title, is_completed, sort_order, created_at, updated_at FROM subtasks WHERE id = :id LIMIT 1');
    $statement->execute([':id' => $subtaskId]);
    $row = $statement->fetch();
    $statement->closeCursor();

    if (!is_array($row)) {
        return null;
    }

    return [
        'id' => isset($row['id']) && is_string($row['id']) ? trim($row['id']) : '',
        'task_id' => isset($row['task_id']) && is_string($row['task_id']) ? trim($row['task_id']) : '',
        'title' => isset($row['title']) && is_string($row['title']) ? trim($row['title']) : '',
        'is_completed' => isset($row['is_completed']) ? (int) $row['is_completed'] : 0,
        'sort_order' => isset($row['sort_order']) ? (int) $row['sort_order'] : 0,
        'created_at' => isset($row['created_at']) && is_string($row['created_at']) ? trim($row['created_at']) : '',
        'updated_at' => isset($row['updated_at']) && is_string($row['updated_at']) ? trim($row['updated_at']) : '',
    ];
}

function loadCommentsForTask(PDO $pdo, string $taskId): array
{
    writeDebugLog('task_comments_load', [
        'task_id' => $taskId,
    ], 'started');

    try {
        $statement = $pdo->prepare(
            "SELECT id, task_id, content, created_at, updated_at
            FROM comments
            WHERE task_id = :task_id
            ORDER BY created_at ASC"
        );
        $statement->execute([':task_id' => $taskId]);
        $rows = $statement->fetchAll();
        $statement->closeCursor();

        $comments = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $commentId = isset($row['id']) && is_string($row['id']) ? trim($row['id']) : '';
            $commentContent = isset($row['content']) && is_string($row['content']) ? trim($row['content']) : '';
            $commentCreatedAt = isset($row['created_at']) && is_string($row['created_at']) ? trim($row['created_at']) : '';
            $comments[] = [
                'id' => $commentId,
                'task_id' => isset($row['task_id']) && is_string($row['task_id']) ? trim($row['task_id']) : '',
                'content' => $commentContent,
                'created_at' => $commentCreatedAt,
                'updated_at' => isset($row['updated_at']) && is_string($row['updated_at']) ? trim($row['updated_at']) : '',
            ];
            writeDebugLog('comment_read', [
                'comment_id' => $commentId,
                'task_id' => $taskId,
                'content_length' => stringLength($commentContent),
                'created_at' => $commentCreatedAt,
            ], 'success');
        }

        writeDebugLog('task_comments_load', [
            'task_id' => $taskId,
        ], 'success', [
            'comment_count' => count($comments),
        ]);

        return $comments;
    } catch (Throwable $exception) {
        writeDebugLog('task_comments_load', [
            'task_id' => $taskId,
        ], 'failed', [
            'reason' => 'database_query_exception',
            'message' => $exception->getMessage(),
        ]);
        return [];
    }
}

function createTaskHistoryId(): string
{
    try {
        return 'history-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
    } catch (Throwable $exception) {
        return 'history-' . date('YmdHis') . '-' . str_replace('.', '', uniqid('', true));
    }
}

function buildTaskFieldChanges(array $before, array $after, array $fields): array
{
    $changes = [];
    foreach ($fields as $field) {
        if (!is_string($field) || $field === '') {
            continue;
        }
        $oldValue = array_key_exists($field, $before) ? $before[$field] : null;
        $newValue = array_key_exists($field, $after) ? $after[$field] : null;
        if ($oldValue !== $newValue) {
            $changes[$field] = [
                'before' => $oldValue,
                'after' => $newValue,
            ];
        }
    }

    return $changes;
}

function recordTaskHistory(string $taskId, string $operationType, array $fieldChanges, string $resultStatus, array $resultContext = []): bool
{
    $historyId = createTaskHistoryId();
    $createdAt = date('Y-m-d H:i:s');

    writeDebugLog('task_history_write', [
        'history_id' => $historyId,
        'task_id' => $taskId,
        'operation_type' => $operationType,
        'field_change_count' => count($fieldChanges),
        'result_status' => $resultStatus,
    ], 'started', [
        'created_at' => $createdAt,
    ]);

    try {
        $pdo = getDatabaseConnection();
        $fieldChangesJson = json_encode($fieldChanges, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $resultJson = json_encode($resultContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($fieldChangesJson) || !is_string($resultJson)) {
            throw new RuntimeException('历史记录 JSON 编码失败。');
        }

        $statement = $pdo->prepare(
            "INSERT INTO task_histories
            (id, task_id, operation_type, field_changes_json, result_status, result_json, created_at)
            VALUES
            (:id, :task_id, :operation_type, :field_changes_json, :result_status, :result_json, :created_at)"
        );
        $statement->execute([
            ':id' => $historyId,
            ':task_id' => $taskId,
            ':operation_type' => $operationType,
            ':field_changes_json' => $fieldChangesJson,
            ':result_status' => $resultStatus,
            ':result_json' => $resultJson,
            ':created_at' => $createdAt,
        ]);
        $statement->closeCursor();

        writeDebugLog('task_history_write', [
            'history_id' => $historyId,
            'task_id' => $taskId,
            'operation_type' => $operationType,
            'field_change_count' => count($fieldChanges),
            'result_status' => $resultStatus,
        ], 'success', [
            'database_file' => basename(DB_FILE),
            'created_at' => $createdAt,
        ]);

        return true;
    } catch (Throwable $exception) {
        $GLOBALS['lastTaskHistoryError'] = $exception->getMessage();
        writeDebugLog('task_history_write_failed', [
            'history_id' => $historyId,
            'task_id' => $taskId,
            'operation_type' => $operationType,
            'field_change_count' => count($fieldChanges),
            'result_status' => $resultStatus,
        ], 'failed', [
            'reason' => 'history_write_exception',
            'message' => $exception->getMessage(),
            'main_operation_rollback' => false,
            'strategy' => 'main_operation_kept_history_failure_logged',
            'database_file' => basename(DB_FILE),
        ]);

        return false;
    }
}

function getLastTaskHistoryError(): string
{
    return isset($GLOBALS['lastTaskHistoryError']) && is_string($GLOBALS['lastTaskHistoryError'])
        ? $GLOBALS['lastTaskHistoryError']
        : '';
}

function loadTaskHistory(PDO $pdo, string $taskId, int $limit = 20): array
{
    writeDebugLog('task_history_load', [
        'task_id' => $taskId,
        'limit' => $limit,
    ], 'started');

    try {
        $safeLimit = max(1, min(100, $limit));
        $statement = $pdo->prepare(
            "SELECT id, task_id, operation_type, field_changes_json, result_status, result_json, created_at
            FROM task_histories
            WHERE task_id = :task_id
            ORDER BY datetime(created_at) DESC, rowid DESC
            LIMIT :limit"
        );
        $statement->bindValue(':task_id', $taskId, PDO::PARAM_STR);
        $statement->bindValue(':limit', $safeLimit, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll();
        $statement->closeCursor();

        $history = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $fieldChanges = json_decode((string) ($row['field_changes_json'] ?? '{}'), true);
            $result = json_decode((string) ($row['result_json'] ?? '{}'), true);
            $history[] = [
                'id' => isset($row['id']) && is_string($row['id']) ? trim($row['id']) : '',
                'task_id' => isset($row['task_id']) && is_string($row['task_id']) ? trim($row['task_id']) : '',
                'operation' => isset($row['operation_type']) && is_string($row['operation_type']) ? trim($row['operation_type']) : '',
                'status' => isset($row['result_status']) && is_string($row['result_status']) ? trim($row['result_status']) : '',
                'field_changes' => is_array($fieldChanges) ? $fieldChanges : [],
                'result' => is_array($result) ? $result : [],
                'created_at' => isset($row['created_at']) && is_string($row['created_at']) ? trim($row['created_at']) : '',
            ];
        }

        writeDebugLog('task_history_load', [
            'task_id' => $taskId,
            'limit' => $safeLimit,
        ], 'success', [
            'history_count' => count($history),
            'result_order' => 'created_at_desc',
        ]);

        return $history;
    } catch (Throwable $exception) {
        writeDebugLog('task_history_load', [
            'task_id' => $taskId,
            'limit' => $limit,
        ], 'failed', [
            'reason' => 'database_query_exception',
            'message' => $exception->getMessage(),
            'history_display_degraded' => true,
        ]);
        return [];
    }
}

function getTaskHistoryOperationLabel(string $operationType): string
{
    $labels = [
        'create' => '创建任务',
        'edit' => '编辑任务',
        'status_change' => '状态变更',
        'archive' => '归档任务',
        'restore_archive' => '恢复归档',
        'delete' => '移入回收站',
        'restore_trash' => '回收站恢复',
        'permanent_delete' => '永久删除',
        'bulk_category_update' => '批量修改分类',
        'bulk_priority_update' => '批量修改优先级',
        'tag_assign' => '标签变更',
    ];

    return $labels[$operationType] ?? $operationType;
}

function getTaskHistoryFieldLabel(string $field): string
{
    $labels = [
        'title' => '标题',
        'content' => '内容',
        'status' => '状态',
        'priority' => '优先级',
        'category_id' => '分类',
        'due_at' => '截止时间',
        'remind_at' => '提醒时间',
        'repeat_rule' => '重复规则',
        'archived_at' => '归档时间',
        'archive_previous_status' => '归档前状态',
        'deleted_at' => '删除时间',
        'tag_ids' => '标签',
    ];

    return $labels[$field] ?? $field;
}

function stringifyHistoryValue($value): string
{
    if ($value === null || $value === '') {
        return '空';
    }
    if (is_bool($value)) {
        return $value ? '是' : '否';
    }
    if (is_array($value)) {
        if ($value === []) {
            return '空';
        }
        $encoded = json_encode(array_values($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($encoded) ? $encoded : '数组';
    }

    return (string) $value;
}

function validateCommentInput(string $content, string $taskId, array $existingTasks): array
{
    $errors = [];
    $normalizedContent = trim($content);

    writeDebugLog('comment_validation', [
        'task_id' => $taskId,
        'content_length' => stringLength($normalizedContent),
        'max_length' => MAX_COMMENT_CONTENT_LENGTH,
    ], 'started');

    if ($normalizedContent === '') {
        $errors['content'] = '评论内容不能为空。';
        writeDebugLog('comment_validation', [
            'task_id' => $taskId,
        ], 'failed', [
            'reason' => 'empty_content',
            'database_write_blocked' => true,
        ]);
    } elseif (stringLength($normalizedContent) > MAX_COMMENT_CONTENT_LENGTH) {
        $errors['content'] = '评论内容不能超过 ' . MAX_COMMENT_CONTENT_LENGTH . ' 个字符。';
        writeDebugLog('comment_validation', [
            'task_id' => $taskId,
            'content_length' => stringLength($normalizedContent),
        ], 'failed', [
            'reason' => 'content_too_long',
            'max_length' => MAX_COMMENT_CONTENT_LENGTH,
            'database_write_blocked' => true,
        ]);
    } else {
        writeDebugLog('comment_validation', [
            'task_id' => $taskId,
            'content_length' => stringLength($normalizedContent),
        ], 'success');
    }

    return [
        'valid' => empty($errors),
        'errors' => $errors,
        'content' => $normalizedContent,
    ];
}

function createCommentId(): string
{
    try {
        return 'comment-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
    } catch (Throwable $exception) {
        return 'comment-' . date('YmdHis') . '-' . str_replace('.', '', uniqid('', true));
    }
}

function saveComment(string $taskId, string $content): string
{
    writeDebugLog('comment_create', [
        'task_id' => $taskId,
        'content_length' => stringLength($content),
    ], 'started');

    try {
        $pdo = getDatabaseConnection();
        $now = date('Y-m-d H:i:s');
        $commentId = createCommentId();

        $statement = $pdo->prepare(
            "INSERT INTO comments (id, task_id, content, created_at, updated_at)
            VALUES (:id, :task_id, :content, :created_at, :updated_at)"
        );

        $statement->execute([
            ':id' => $commentId,
            ':task_id' => $taskId,
            ':content' => $content,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        $statement->closeCursor();

        writeDebugLog('comment_create', [
            'comment_id' => $commentId,
            'task_id' => $taskId,
            'content_length' => stringLength($content),
        ], 'success', [
            'created_at' => $now,
        ]);

        return 'success';
    } catch (Throwable $exception) {
        writeDebugLog('comment_create', [
            'task_id' => $taskId,
        ], 'failed', [
            'reason' => 'database_write_exception',
            'message' => $exception->getMessage(),
        ]);
        return 'failed';
    }
}

function validateAttachmentInput(string $fileName, int $fileSize, string $mimeType, string $taskId): array
{
    $errors = [];
    $normalizedFileName = trim($fileName);

    writeDebugLog('attachment_validation', [
        'task_id' => $taskId,
        'file_name_length' => stringLength($normalizedFileName),
        'file_size' => $fileSize,
        'mime_type' => $mimeType,
        'max_file_size' => MAX_ATTACHMENT_FILE_SIZE,
        'max_file_name_length' => MAX_ATTACHMENT_FILE_NAME_LENGTH,
    ], 'started');

    if ($normalizedFileName === '') {
        $errors['file_name'] = '文件名不能为空。';
        writeDebugLog('attachment_validation', [
            'task_id' => $taskId,
        ], 'failed', [
            'reason' => 'empty_file_name',
            'database_write_blocked' => true,
        ]);
    } elseif (stringLength($normalizedFileName) > MAX_ATTACHMENT_FILE_NAME_LENGTH) {
        $errors['file_name'] = '文件名不能超过 ' . MAX_ATTACHMENT_FILE_NAME_LENGTH . ' 个字符。';
        writeDebugLog('attachment_validation', [
            'task_id' => $taskId,
            'file_name_length' => stringLength($normalizedFileName),
        ], 'failed', [
            'reason' => 'file_name_too_long',
            'max_length' => MAX_ATTACHMENT_FILE_NAME_LENGTH,
            'database_write_blocked' => true,
        ]);
    }

    if ($fileSize <= 0) {
        $errors['file_size'] = '文件大小必须大于 0。';
        writeDebugLog('attachment_validation', [
            'task_id' => $taskId,
            'file_name' => $normalizedFileName,
        ], 'failed', [
            'reason' => 'invalid_file_size',
            'database_write_blocked' => true,
        ]);
    } elseif ($fileSize > MAX_ATTACHMENT_FILE_SIZE) {
        $errors['file_size'] = '文件大小不能超过 ' . (MAX_ATTACHMENT_FILE_SIZE / 1024 / 1024) . ' MB。';
        writeDebugLog('attachment_validation', [
            'task_id' => $taskId,
            'file_name' => $normalizedFileName,
            'file_size' => $fileSize,
        ], 'failed', [
            'reason' => 'file_size_too_large',
            'max_size' => MAX_ATTACHMENT_FILE_SIZE,
            'database_write_blocked' => true,
        ]);
    }

    $normalizedMimeType = trim(strtolower($mimeType));
    if ($normalizedMimeType !== '' && !in_array($normalizedMimeType, ALLOWED_ATTACHMENT_MIME_TYPES, true)) {
        $errors['mime_type'] = '不支持的文件类型：' . escapeHtml($mimeType) . '。允许的类型：' . implode(', ', ALLOWED_ATTACHMENT_MIME_TYPES);
        writeDebugLog('attachment_validation', [
            'task_id' => $taskId,
            'file_name' => $normalizedFileName,
            'mime_type' => $mimeType,
        ], 'failed', [
            'reason' => 'invalid_mime_type',
            'allowed_types' => ALLOWED_ATTACHMENT_MIME_TYPES,
            'database_write_blocked' => true,
        ]);
    }

    if (empty($errors)) {
        writeDebugLog('attachment_validation', [
            'task_id' => $taskId,
            'file_name' => $normalizedFileName,
            'file_size' => $fileSize,
            'mime_type' => $normalizedMimeType,
        ], 'success');
    }

    return [
        'valid' => empty($errors),
        'errors' => $errors,
        'file_name' => $normalizedFileName,
        'mime_type' => $normalizedMimeType,
    ];
}

function createAttachmentId(): string
{
    try {
        return 'attach-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
    } catch (Throwable $exception) {
        return 'attach-' . date('YmdHis') . '-' . str_replace('.', '', uniqid('', true));
    }
}

function loadAttachmentsForTask(PDO $pdo, string $taskId): array
{
    writeDebugLog('task_attachments_load', [
        'task_id' => $taskId,
    ], 'started');

    try {
        $statement = $pdo->prepare(
            "SELECT id, task_id, file_name, file_size, mime_type, storage_path, created_at, updated_at
            FROM attachments
            WHERE task_id = :task_id
            ORDER BY created_at ASC"
        );
        $statement->execute([':task_id' => $taskId]);
        $rows = $statement->fetchAll();
        $statement->closeCursor();

        $attachments = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $attachmentId = isset($row['id']) && is_string($row['id']) ? trim($row['id']) : '';
            $attachmentFileName = isset($row['file_name']) && is_string($row['file_name']) ? trim($row['file_name']) : '';
            $attachmentFileSize = isset($row['file_size']) && is_int($row['file_size']) ? $row['file_size'] : 0;
            $attachmentMimeType = isset($row['mime_type']) && is_string($row['mime_type']) ? trim($row['mime_type']) : '';
            $attachmentStoragePath = isset($row['storage_path']) && is_string($row['storage_path']) ? trim($row['storage_path']) : '';
            $attachmentCreatedAt = isset($row['created_at']) && is_string($row['created_at']) ? trim($row['created_at']) : '';

            $attachments[] = [
                'id' => $attachmentId,
                'task_id' => isset($row['task_id']) && is_string($row['task_id']) ? trim($row['task_id']) : '',
                'file_name' => $attachmentFileName,
                'file_size' => $attachmentFileSize,
                'mime_type' => $attachmentMimeType,
                'storage_path' => $attachmentStoragePath,
                'created_at' => $attachmentCreatedAt,
                'updated_at' => isset($row['updated_at']) && is_string($row['updated_at']) ? trim($row['updated_at']) : '',
            ];

            writeDebugLog('attachment_read', [
                'attachment_id' => $attachmentId,
                'task_id' => $taskId,
                'file_name' => $attachmentFileName,
                'file_size' => $attachmentFileSize,
                'mime_type' => $attachmentMimeType,
                'created_at' => $attachmentCreatedAt,
            ], 'success');
        }

        writeDebugLog('task_attachments_load', [
            'task_id' => $taskId,
        ], 'success', [
            'attachment_count' => count($attachments),
        ]);

        return $attachments;
    } catch (Throwable $exception) {
        writeDebugLog('task_attachments_load', [
            'task_id' => $taskId,
        ], 'failed', [
            'reason' => 'database_query_exception',
            'message' => $exception->getMessage(),
        ]);
        return [];
    }
}

function saveNewAttachment(string $taskId, string $fileName, int $fileSize, string $mimeType, string $storagePath): string
{
    writeDebugLog('attachment_create', [
        'task_id' => $taskId,
        'file_name' => $fileName,
        'file_size' => $fileSize,
        'mime_type' => $mimeType,
    ], 'started');

    try {
        $pdo = getDatabaseConnection();
        $now = date('Y-m-d H:i:s');
        $attachmentId = createAttachmentId();

        $statement = $pdo->prepare(
            "INSERT INTO attachments (id, task_id, file_name, file_size, mime_type, storage_path, created_at, updated_at)
            VALUES (:id, :task_id, :file_name, :file_size, :mime_type, :storage_path, :created_at, :updated_at)"
        );

        $statement->execute([
            ':id' => $attachmentId,
            ':task_id' => $taskId,
            ':file_name' => $fileName,
            ':file_size' => $fileSize,
            ':mime_type' => $mimeType,
            ':storage_path' => $storagePath,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        $statement->closeCursor();

        writeDebugLog('attachment_create', [
            'attachment_id' => $attachmentId,
            'task_id' => $taskId,
            'file_name' => $fileName,
            'file_size' => $fileSize,
            'mime_type' => $mimeType,
        ], 'success', [
            'created_at' => $now,
        ]);

        return 'success';
    } catch (Throwable $exception) {
        writeDebugLog('attachment_create', [
            'task_id' => $taskId,
            'file_name' => $fileName,
        ], 'failed', [
            'reason' => 'database_write_exception',
            'message' => $exception->getMessage(),
        ]);
        return 'failed';
    }
}

function deleteAttachmentById(string $attachmentId, string $taskId): string
{
    writeDebugLog('attachment_delete', [
        'attachment_id' => $attachmentId,
        'task_id' => $taskId,
    ], 'started');

    try {
        $pdo = getDatabaseConnection();

        $existingStatement = $pdo->prepare('SELECT id, task_id, file_name, storage_path FROM attachments WHERE id = :id LIMIT 1');
        $existingStatement->execute([':id' => $attachmentId]);
        $existingAttachment = $existingStatement->fetch();
        $existingStatement->closeCursor();

        if (!is_array($existingAttachment)) {
            writeDebugLog('attachment_delete', [
                'attachment_id' => $attachmentId,
                'task_id' => $taskId,
            ], 'failed', [
                'reason' => $attachmentId === '' ? 'empty_attachment_id' : 'missing_attachment',
            ]);
            return 'not_found';
        }

        $deleteStatement = $pdo->prepare('DELETE FROM attachments WHERE id = :id');
        $deleteStatement->execute([':id' => $attachmentId]);
        $changedRows = $deleteStatement->rowCount();
        $deleteStatement->closeCursor();

        writeDebugLog('attachment_delete', [
            'attachment_id' => $attachmentId,
            'task_id' => $taskId,
            'file_name' => isset($existingAttachment['file_name']) ? $existingAttachment['file_name'] : '',
        ], 'success', [
            'rows_affected' => $changedRows,
        ]);

        return 'success';
    } catch (Throwable $exception) {
        writeDebugLog('attachment_delete', [
            'attachment_id' => $attachmentId,
            'task_id' => $taskId,
        ], 'failed', [
            'reason' => 'database_write_exception',
            'message' => $exception->getMessage(),
        ]);
        return 'failed';
    }
}

function createTaskId(): string
{
    try {
        return 'task-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
    } catch (Throwable $exception) {
        return 'task-' . date('YmdHis') . '-' . str_replace('.', '', uniqid('', true));
    }
}

function saveTaskReminder(string $taskId, string $remindAt, string $status = 'pending'): bool
{
    try {
        $pdo = getDatabaseConnection();
        $now = date('Y-m-d H:i:s');

        $existingStatement = $pdo->prepare('SELECT id FROM reminders WHERE task_id = :task_id LIMIT 1');
        $existingStatement->execute([':task_id' => $taskId]);
        $existingReminder = $existingStatement->fetch();
        $existingStatement->closeCursor();

        if (is_array($existingReminder)) {
            $updateStatement = $pdo->prepare(
                "UPDATE reminders
                SET remind_at = :remind_at,
                    status = :status,
                    updated_at = :updated_at
                WHERE task_id = :task_id"
            );
            $updateStatement->execute([
                ':remind_at' => $remindAt,
                ':status' => $status,
                ':updated_at' => $now,
                ':task_id' => $taskId,
            ]);
            $updateStatement->closeCursor();

            writeDebugLog('task_reminder_update', [
                'task_id' => $taskId,
                'remind_at' => $remindAt,
                'status' => $status,
            ], 'success', [
                'database_file' => basename(DB_FILE),
                'updated_at' => $now,
            ]);
        } else {
            $reminderId = 'reminder-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
            $insertStatement = $pdo->prepare(
                "INSERT INTO reminders (id, task_id, remind_at, status, created_at, updated_at)
                VALUES (:id, :task_id, :remind_at, :status, :created_at, :updated_at)"
            );
            $insertStatement->execute([
                ':id' => $reminderId,
                ':task_id' => $taskId,
                ':remind_at' => $remindAt,
                ':status' => $status,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
            $insertStatement->closeCursor();

            writeDebugLog('task_reminder_create', [
                'task_id' => $taskId,
                'reminder_id' => $reminderId,
                'remind_at' => $remindAt,
                'status' => $status,
            ], 'success', [
                'database_file' => basename(DB_FILE),
                'created_at' => $now,
            ]);
        }

        return true;
    } catch (Throwable $exception) {
        writeDebugLog('task_reminder_save_exception', [
            'task_id' => $taskId,
            'remind_at' => $remindAt,
        ], 'failed', [
            'reason' => 'database_write_exception',
            'message' => $exception->getMessage(),
            'database_file' => basename(DB_FILE),
        ]);
        return false;
    }
}

function deleteTaskReminder(string $taskId): bool
{
    try {
        $pdo = getDatabaseConnection();
        $statement = $pdo->prepare('DELETE FROM reminders WHERE task_id = :task_id');
        $statement->execute([':task_id' => $taskId]);
        $statement->closeCursor();

        writeDebugLog('task_reminder_delete', [
            'task_id' => $taskId,
        ], 'success', [
            'database_file' => basename(DB_FILE),
        ]);

        return true;
    } catch (Throwable $exception) {
        writeDebugLog('task_reminder_delete_exception', [
            'task_id' => $taskId,
        ], 'failed', [
            'reason' => 'database_delete_exception',
            'message' => $exception->getMessage(),
            'database_file' => basename(DB_FILE),
        ]);
        return false;
    }
}

function loadTaskReminder(string $taskId): ?array
{
    try {
        $pdo = getDatabaseConnection();
        $statement = $pdo->prepare('SELECT * FROM reminders WHERE task_id = :task_id LIMIT 1');
        $statement->execute([':task_id' => $taskId]);
        $row = $statement->fetch();
        $statement->closeCursor();

        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => isset($row['id']) && is_string($row['id']) ? trim($row['id']) : '',
            'task_id' => isset($row['task_id']) && is_string($row['task_id']) ? trim($row['task_id']) : '',
            'remind_at' => isset($row['remind_at']) && is_string($row['remind_at']) ? trim($row['remind_at']) : '',
            'status' => isset($row['status']) && is_string($row['status']) ? trim($row['status']) : 'pending',
            'delivered_at' => isset($row['delivered_at']) && is_string($row['delivered_at']) ? trim($row['delivered_at']) : '',
            'created_at' => isset($row['created_at']) && is_string($row['created_at']) ? trim($row['created_at']) : '',
            'updated_at' => isset($row['updated_at']) && is_string($row['updated_at']) ? trim($row['updated_at']) : '',
        ];
    } catch (Throwable $exception) {
        writeDebugLog('task_reminder_load_exception', [
            'task_id' => $taskId,
        ], 'failed', [
            'reason' => 'database_read_exception',
            'message' => $exception->getMessage(),
            'database_file' => basename(DB_FILE),
        ]);
        return null;
    }
}

function saveNewTask(array $task): bool
{
    try {
        $pdo = getDatabaseConnection();
        $now = date('Y-m-d H:i:s');
        $createdAt = isset($task['created_at']) && is_string($task['created_at']) && trim($task['created_at']) !== ''
            ? trim($task['created_at'])
            : $now;
        $updatedAt = isset($task['updated_at']) && is_string($task['updated_at']) && trim($task['updated_at']) !== ''
            ? trim($task['updated_at'])
            : $createdAt;
        $normalizedStatus = normalizeTaskStatus((string) $task['status']);
        $archivedAt = $normalizedStatus === '已归档' ? $updatedAt : null;
        $archivePreviousStatus = $normalizedStatus === '已归档' ? '未开始' : null;
        $priority = normalizeTaskPriority((string) ($task['priority'] ?? DEFAULT_TASK_PRIORITY));
        $dueAt = normalizeStoredDueAt($task['due_at'] ?? '');
        $categoryId = isset($task['category_id']) && is_string($task['category_id']) && trim($task['category_id']) !== ''
            ? trim($task['category_id'])
            : null;
        $repeatRule = isset($task['repeat_rule']) && is_string($task['repeat_rule']) ? trim($task['repeat_rule']) : '';
        $statement = $pdo->prepare(
            "INSERT INTO tasks
            (id, title, content, status, priority, category_id, due_at, repeat_rule, archived_at, archive_previous_status, deleted_at, created_at, updated_at)
            VALUES
            (:id, :title, :content, :status, :priority, :category_id, :due_at, :repeat_rule, :archived_at, :archive_previous_status, NULL, :created_at, :updated_at)"
        );

        $statement->execute([
            ':id' => (string) $task['id'],
            ':title' => (string) $task['title'],
            ':content' => (string) $task['content'],
            ':status' => $normalizedStatus,
            ':priority' => $priority,
            ':category_id' => $categoryId,
            ':due_at' => $dueAt !== '' ? $dueAt : null,
            ':repeat_rule' => $repeatRule !== '' ? $repeatRule : null,
            ':archived_at' => $archivedAt,
            ':archive_previous_status' => $archivePreviousStatus,
            ':created_at' => $createdAt,
            ':updated_at' => $updatedAt,
        ]);
        $statement->closeCursor();

        $countStatement = $pdo->query('SELECT COUNT(*) FROM tasks WHERE deleted_at IS NULL');
        $taskCount = $countStatement !== false ? (int) $countStatement->fetchColumn() : 0;
        if ($countStatement !== false) {
            $countStatement->closeCursor();
        }

        writeDebugLog('task_create_save_success', [
            'task_id' => (string) $task['id'],
            'title_length' => stringLength((string) $task['title']),
            'content_length' => stringLength((string) $task['content']),
            'status' => $normalizedStatus,
            'priority' => $priority,
            'category_id' => $categoryId ?? '',
            'due_at' => $dueAt,
            'repeat_rule' => $repeatRule,
            'remind_at' => normalizeStoredRemindAt($task['remind_at'] ?? ''),
        ], 'success', [
            'task_count' => $taskCount,
            'database_file' => basename(DB_FILE),
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
            'archived_at' => $archivedAt,
            'archive_previous_status' => $archivePreviousStatus,
        ]);

        writeDebugLog('task_category_assign', [
            'task_id' => (string) $task['id'],
            'category_id' => $categoryId ?? '',
            'form_action' => 'create',
        ], 'success', [
            'has_category' => $categoryId !== null,
            'database_file' => basename(DB_FILE),
            'updated_at' => $updatedAt,
        ]);

        writeDebugLog('task_due_at_save', [
            'task_id' => (string) $task['id'],
            'due_at' => $dueAt,
            'form_action' => 'create',
        ], 'success', [
            'database_file' => basename(DB_FILE),
            'saved_value' => $dueAt !== '' ? $dueAt : null,
            'updated_at' => $updatedAt,
        ]);

        writeDebugLog('task_priority_create', [
            'task_id' => (string) $task['id'],
            'priority' => $priority,
        ], 'success', [
            'default_applied' => isset($task['priority_default_applied'])
                ? (bool) $task['priority_default_applied']
                : (!isset($task['priority']) || trim((string) $task['priority']) === ''),
            'created_at' => $createdAt,
            'allowed_priorities' => ALLOWED_PRIORITIES,
        ]);

        if ($repeatRule !== '') {
            writeDebugLog('repeat_rule_save', [
                'task_id' => (string) $task['id'],
                'repeat_rule' => $repeatRule,
                'form_action' => 'create',
            ], 'success', [
                'database_file' => basename(DB_FILE),
                'updated_at' => $updatedAt,
            ]);
        }

        $historyWritten = recordTaskHistory((string) $task['id'], 'create', buildTaskFieldChanges([], [
            'title' => (string) $task['title'],
            'content' => (string) $task['content'],
            'status' => $normalizedStatus,
            'priority' => $priority,
            'category_id' => $categoryId ?? '',
            'due_at' => $dueAt,
            'remind_at' => normalizeStoredRemindAt($task['remind_at'] ?? ''),
            'repeat_rule' => $repeatRule,
            'archived_at' => $archivedAt ?? '',
            'deleted_at' => '',
        ], ['title', 'content', 'status', 'priority', 'category_id', 'due_at', 'remind_at', 'repeat_rule', 'archived_at', 'deleted_at']), 'success', [
            'task_count' => $taskCount,
            'changed_rows' => 1,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ]);
        if (!$historyWritten) {
            writeDebugLog('task_create_history_warning', [
                'task_id' => (string) $task['id'],
                'operation_type' => 'create',
            ], 'failed', [
                'reason' => 'history_write_failed_after_task_create',
                'message' => getLastTaskHistoryError(),
                'main_operation_kept' => true,
            ]);
        }

        return true;
    } catch (Throwable $exception) {
        writeDebugLog('task_create_save_exception', [
            'task_id' => (string) ($task['id'] ?? ''),
            'title_length' => stringLength((string) ($task['title'] ?? '')),
            'status' => (string) ($task['status'] ?? ''),
            'priority' => (string) ($task['priority'] ?? ''),
            'category_id' => (string) ($task['category_id'] ?? ''),
        ], 'failed', [
            'reason' => 'database_write_exception',
            'message' => $exception->getMessage(),
            'database_file' => basename(DB_FILE),
        ]);
        return false;
    }
}

function saveUpdatedTask(string $taskId, string $title, string $content, string $status, string $priority, string $dueAt, string $remindAt, string $categoryId, string $repeatRule = ''): string
{
    try {
        $pdo = getDatabaseConnection();
        $existingStatement = $pdo->prepare('SELECT id, title, content, status, priority, category_id, due_at, repeat_rule, archived_at, archive_previous_status, created_at FROM tasks WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $existingStatement->execute([':id' => $taskId]);
        $existingTask = $existingStatement->fetch();
        $existingStatement->closeCursor();

        if (!is_array($existingTask)) {
            writeDebugLog('task_edit_not_found', [
                'task_id' => $taskId,
                'title_length' => stringLength($title),
                'content_length' => stringLength($content),
                'status' => $status,
                'priority' => $priority,
                'category_id' => $categoryId,
                'due_at' => $dueAt,
                'remind_at' => $remindAt,
                'repeat_rule' => $repeatRule,
            ], 'failed', [
                'stage' => 'save',
            ]);
            return 'not_found';
        }

        $updatedAt = date('Y-m-d H:i:s');
        $currentArchivedAt = isset($existingTask['archived_at']) && is_string($existingTask['archived_at'])
            ? trim($existingTask['archived_at'])
            : '';
        $previousStatus = normalizeTaskStatus((string) ($existingTask['status'] ?? '未开始'));
        $currentArchivePreviousStatus = isset($existingTask['archive_previous_status']) && is_string($existingTask['archive_previous_status'])
            ? trim($existingTask['archive_previous_status'])
            : '';
        $archivedAt = $status === '已归档' ? ($currentArchivedAt !== '' ? $currentArchivedAt : $updatedAt) : null;
        $archivePreviousStatus = null;
        if ($status === '已归档') {
            $archivePreviousStatus = $previousStatus === '已归档'
                ? ($currentArchivePreviousStatus !== '' ? normalizeTaskStatus($currentArchivePreviousStatus) : '未开始')
                : $previousStatus;
        }
        $previousRepeatRule = isset($existingTask['repeat_rule']) && is_string($existingTask['repeat_rule']) ? trim($existingTask['repeat_rule']) : '';
        $updateStatement = $pdo->prepare(
            "UPDATE tasks
            SET title = :title,
                content = :content,
                status = :status,
                priority = :priority,
                category_id = :category_id,
                due_at = :due_at,
                repeat_rule = :repeat_rule,
                archived_at = :archived_at,
                archive_previous_status = :archive_previous_status,
                updated_at = :updated_at
            WHERE id = :id
                AND deleted_at IS NULL"
        );
        $updateStatement->execute([
            ':title' => $title,
            ':content' => $content,
            ':status' => $status,
            ':priority' => $priority,
            ':category_id' => $categoryId !== '' ? $categoryId : null,
            ':due_at' => $dueAt !== '' ? $dueAt : null,
            ':repeat_rule' => $repeatRule !== '' ? $repeatRule : null,
            ':archived_at' => $archivedAt,
            ':archive_previous_status' => $archivePreviousStatus,
            ':updated_at' => $updatedAt,
            ':id' => $taskId,
        ]);
        $changedRows = $updateStatement->rowCount();
        $updateStatement->closeCursor();

        $countStatement = $pdo->query('SELECT COUNT(*) FROM tasks WHERE deleted_at IS NULL');
        $taskCount = $countStatement !== false ? (int) $countStatement->fetchColumn() : 0;
        if ($countStatement !== false) {
            $countStatement->closeCursor();
        }

        writeDebugLog('task_edit_save_success', [
            'task_id' => $taskId,
            'title_length' => stringLength($title),
            'content_length' => stringLength($content),
            'status' => $status,
            'priority' => $priority,
            'category_id' => $categoryId,
            'due_at' => $dueAt,
            'remind_at' => $remindAt,
            'repeat_rule' => $repeatRule,
        ], 'success', [
            'task_count' => $taskCount,
            'database_file' => basename(DB_FILE),
            'previous_status' => (string) $existingTask['status'],
            'previous_priority' => normalizeTaskPriority((string) ($existingTask['priority'] ?? DEFAULT_TASK_PRIORITY)),
            'previous_category_id' => isset($existingTask['category_id']) && is_string($existingTask['category_id']) ? trim($existingTask['category_id']) : '',
            'previous_due_at' => normalizeStoredDueAt($existingTask['due_at'] ?? ''),
            'previous_repeat_rule' => $previousRepeatRule,
            'created_at' => (string) $existingTask['created_at'],
            'updated_at' => $updatedAt,
            'archived_at' => $archivedAt,
            'archive_previous_status' => $archivePreviousStatus,
        ]);

        $previousCategoryId = isset($existingTask['category_id']) && is_string($existingTask['category_id']) ? trim($existingTask['category_id']) : '';
        writeDebugLog('task_category_assign', [
            'task_id' => $taskId,
            'previous_category_id' => $previousCategoryId,
            'category_id' => $categoryId,
            'form_action' => 'edit',
        ], 'success', [
            'changed' => $previousCategoryId !== $categoryId,
            'has_category' => $categoryId !== '',
            'database_file' => basename(DB_FILE),
            'updated_at' => $updatedAt,
        ]);

        writeDebugLog('task_due_at_save', [
            'task_id' => $taskId,
            'due_at' => $dueAt,
            'form_action' => 'edit',
        ], 'success', [
            'database_file' => basename(DB_FILE),
            'previous_due_at' => normalizeStoredDueAt($existingTask['due_at'] ?? ''),
            'changed' => normalizeStoredDueAt($existingTask['due_at'] ?? '') !== $dueAt,
            'saved_value' => $dueAt !== '' ? $dueAt : null,
            'updated_at' => $updatedAt,
        ]);

        writeDebugLog('task_repeat_rule_save', [
            'task_id' => $taskId,
            'previous_repeat_rule' => $previousRepeatRule,
            'repeat_rule' => $repeatRule,
            'form_action' => 'edit',
        ], 'success', [
            'database_file' => basename(DB_FILE),
            'changed' => $previousRepeatRule !== $repeatRule,
            'updated_at' => $updatedAt,
        ]);

        $previousReminder = loadTaskReminder($taskId);
        $previousRemindAt = $previousReminder !== null ? normalizeStoredRemindAt($previousReminder['remind_at']) : '';
        if ($remindAt !== '') {
            saveTaskReminder($taskId, $remindAt, 'pending');
            writeDebugLog('task_remind_at_save', [
                'task_id' => $taskId,
                'remind_at' => $remindAt,
                'form_action' => 'edit',
            ], 'success', [
                'database_file' => basename(DB_FILE),
                'previous_remind_at' => $previousRemindAt,
                'changed' => $previousRemindAt !== $remindAt,
                'saved_value' => $remindAt,
                'updated_at' => $updatedAt,
            ]);
        } else {
            if ($previousRemindAt !== '') {
                deleteTaskReminder($taskId);
                writeDebugLog('task_remind_at_clear', [
                    'task_id' => $taskId,
                    'previous_remind_at' => $previousRemindAt,
                    'form_action' => 'edit',
                ], 'success', [
                    'database_file' => basename(DB_FILE),
                    'updated_at' => $updatedAt,
                ]);
            }
        }

        writeDebugLog('task_priority_update', [
            'task_id' => $taskId,
            'previous_priority' => normalizeTaskPriority((string) ($existingTask['priority'] ?? DEFAULT_TASK_PRIORITY)),
            'new_priority' => $priority,
        ], 'success', [
            'changed' => normalizeTaskPriority((string) ($existingTask['priority'] ?? DEFAULT_TASK_PRIORITY)) !== $priority,
            'updated_at' => $updatedAt,
            'allowed_priorities' => ALLOWED_PRIORITIES,
        ]);

        $historyChanges = buildTaskFieldChanges([
            'title' => (string) ($existingTask['title'] ?? ''),
            'content' => (string) ($existingTask['content'] ?? ''),
            'status' => normalizeTaskStatus((string) ($existingTask['status'] ?? '未开始')),
            'priority' => normalizeTaskPriority((string) ($existingTask['priority'] ?? DEFAULT_TASK_PRIORITY)),
            'category_id' => isset($existingTask['category_id']) && is_string($existingTask['category_id']) ? trim($existingTask['category_id']) : '',
            'due_at' => normalizeStoredDueAt($existingTask['due_at'] ?? ''),
            'remind_at' => $previousRemindAt,
            'repeat_rule' => $previousRepeatRule,
            'archived_at' => $currentArchivedAt,
        ], [
            'title' => $title,
            'content' => $content,
            'status' => $status,
            'priority' => $priority,
            'category_id' => $categoryId,
            'due_at' => $dueAt,
            'remind_at' => $remindAt,
            'repeat_rule' => $repeatRule,
            'archived_at' => $archivedAt ?? '',
        ], ['title', 'content', 'status', 'priority', 'category_id', 'due_at', 'remind_at', 'repeat_rule', 'archived_at']);
        $historyWritten = recordTaskHistory($taskId, 'edit', $historyChanges, 'success', [
            'changed_rows' => $changedRows,
            'updated_at' => $updatedAt,
            'changed_field_count' => count($historyChanges),
        ]);
        if (!$historyWritten) {
            writeDebugLog('task_edit_history_warning', [
                'task_id' => $taskId,
                'operation_type' => 'edit',
            ], 'failed', [
                'reason' => 'history_write_failed_after_task_update',
                'message' => getLastTaskHistoryError(),
                'main_operation_kept' => true,
            ]);
        }

        return 'success';
    } catch (Throwable $exception) {
        writeDebugLog('task_edit_save_exception', [
            'task_id' => $taskId,
            'title_length' => stringLength($title),
            'content_length' => stringLength($content),
            'status' => $status,
            'priority' => $priority,
            'category_id' => $categoryId,
            'due_at' => $dueAt,
            'repeat_rule' => $repeatRule,
        ], 'failed', [
            'reason' => 'database_write_exception',
            'message' => $exception->getMessage(),
            'database_file' => basename(DB_FILE),
        ]);
        return 'exception';
    }
}

function updateTaskStatus(string $taskId, string $status): string
{
    setLastRecurrenceGenerationResult('not_applicable');

    if (!isAllowedTaskStatus($status)) {
        writeDebugLog('task_status_invalid_submit', [
            'task_id' => $taskId,
            'submitted_status' => $status,
        ], 'failed', [
            'reason' => 'status_outside_allowed_enum',
            'allowed_statuses' => ALLOWED_STATUSES,
        ]);
        return 'invalid_status';
    }

    try {
        $pdo = getDatabaseConnection();
        $existingStatement = $pdo->prepare('SELECT id, title, status, repeat_rule, archived_at, archive_previous_status, updated_at FROM tasks WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $existingStatement->execute([':id' => $taskId]);
        $existingTask = $existingStatement->fetch();
        $existingStatement->closeCursor();

        if (!is_array($existingTask)) {
            writeDebugLog('task_status_not_found', [
                'task_id' => $taskId,
                'submitted_status' => $status,
            ], 'failed', [
                'reason' => $taskId === '' ? 'empty_task_id' : 'missing_task',
            ]);
            return 'not_found';
        }

        $previousStatus = isset($existingTask['status']) && is_string($existingTask['status']) ? trim($existingTask['status']) : '';
        if (!isAllowedTaskStatus($previousStatus)) {
            writeDebugLog('task_status_read_exception', [
                'task_id' => $taskId,
                'raw_status' => $previousStatus,
            ], 'failed', [
                'reason' => 'status_outside_allowed_enum_before_change',
                'normalized_status' => normalizeTaskStatus($previousStatus),
                'allowed_statuses' => ALLOWED_STATUSES,
            ]);
        }

        $updatedAt = date('Y-m-d H:i:s');
        $currentArchivedAt = isset($existingTask['archived_at']) && is_string($existingTask['archived_at'])
            ? trim($existingTask['archived_at'])
            : '';
        $currentArchivePreviousStatus = isset($existingTask['archive_previous_status']) && is_string($existingTask['archive_previous_status'])
            ? trim($existingTask['archive_previous_status'])
            : '';
        $archivedAt = $status === '已归档' ? ($currentArchivedAt !== '' ? $currentArchivedAt : $updatedAt) : null;
        $archivePreviousStatus = null;
        if ($status === '已归档') {
            $archivePreviousStatus = normalizeTaskStatus($previousStatus) === '已归档'
                ? ($currentArchivePreviousStatus !== '' ? normalizeTaskStatus($currentArchivePreviousStatus) : '未开始')
                : normalizeTaskStatus($previousStatus);
        }
        $statement = $pdo->prepare(
            'UPDATE tasks
            SET status = :status,
                archived_at = :archived_at,
                archive_previous_status = :archive_previous_status,
                updated_at = :updated_at
            WHERE id = :id
                AND deleted_at IS NULL'
        );
        $statement->execute([
            ':status' => $status,
            ':archived_at' => $archivedAt,
            ':archive_previous_status' => $archivePreviousStatus,
            ':updated_at' => $updatedAt,
            ':id' => $taskId,
        ]);
        $changedRows = $statement->rowCount();
        $statement->closeCursor();

        writeDebugLog('task_status_change_success', [
            'task_id' => $taskId,
            'previous_status' => normalizeTaskStatus($previousStatus),
            'new_status' => $status,
        ], 'success', [
            'title_length' => stringLength((string) $existingTask['title']),
            'database_file' => basename(DB_FILE),
            'updated_at' => $updatedAt,
            'archived_at' => $archivedAt,
            'archive_previous_status' => $archivePreviousStatus,
            'changed_rows' => $changedRows,
        ]);

        $historyChanges = buildTaskFieldChanges([
            'status' => normalizeTaskStatus($previousStatus),
            'archived_at' => $currentArchivedAt,
            'archive_previous_status' => $currentArchivePreviousStatus,
        ], [
            'status' => $status,
            'archived_at' => $archivedAt ?? '',
            'archive_previous_status' => $archivePreviousStatus ?? '',
        ], ['status', 'archived_at', 'archive_previous_status']);
        $historyWritten = recordTaskHistory($taskId, 'status_change', $historyChanges, 'success', [
            'changed_rows' => $changedRows,
            'updated_at' => $updatedAt,
            'submitted_status' => $status,
        ]);
        if (!$historyWritten) {
            writeDebugLog('task_status_history_warning', [
                'task_id' => $taskId,
                'operation_type' => 'status_change',
            ], 'failed', [
                'reason' => 'history_write_failed_after_status_change',
                'message' => getLastTaskHistoryError(),
                'main_operation_kept' => true,
            ]);
        }

        if ($status === '已完成') {
            $repeatRule = isset($existingTask['repeat_rule']) && is_string($existingTask['repeat_rule']) ? trim($existingTask['repeat_rule']) : '';
            if ($repeatRule !== '') {
                if (normalizeTaskStatus($previousStatus) === '已完成') {
                    setLastRecurrenceGenerationResult('duplicate', [
                        'task_id' => $taskId,
                        'repeat_rule' => $repeatRule,
                        'previous_status' => $previousStatus,
                    ]);
                    writeDebugLog('repeat_generation_trigger', [
                        'task_id' => $taskId,
                        'repeat_rule' => $repeatRule,
                        'new_status' => $status,
                    ], 'failed', [
                        'reason' => 'task_already_completed',
                        'database_write_blocked' => true,
                    ]);
                    return 'success';
                }

                writeDebugLog('repeat_generation_trigger', [
                    'task_id' => $taskId,
                    'repeat_rule' => $repeatRule,
                    'new_status' => $status,
                ], 'started');

                $nextTask = generateNextRecurrence($taskId);
                if ($nextTask !== null) {
                    setLastRecurrenceGenerationResult('created', [
                        'task_id' => $taskId,
                        'generated_task_id' => $nextTask['id'],
                        'generated_due_at' => $nextTask['due_at'],
                    ]);
                    writeDebugLog('repeat_generation_trigger', [
                        'task_id' => $taskId,
                        'generated_task_id' => $nextTask['id'],
                        'repeat_rule' => $repeatRule,
                        'new_status' => $status,
                    ], 'success', [
                        'generated_task_title' => $nextTask['title'],
                        'generated_task_due_at' => $nextTask['due_at'],
                    ]);
                } else {
                    setLastRecurrenceGenerationResult('skipped', [
                        'task_id' => $taskId,
                        'repeat_rule' => $repeatRule,
                    ]);
                    writeDebugLog('repeat_generation_trigger', [
                        'task_id' => $taskId,
                        'repeat_rule' => $repeatRule,
                        'new_status' => $status,
                    ], 'failed', [
                        'reason' => 'next_occurrence_not_generated',
                    ]);
                }
            }
        }

        return 'success';
    } catch (Throwable $exception) {
        setLastRecurrenceGenerationResult('failed', [
            'task_id' => $taskId,
            'submitted_status' => $status,
        ]);
        writeDebugLog('task_status_change_exception', [
            'task_id' => $taskId,
            'submitted_status' => $status,
        ], 'failed', [
            'reason' => 'database_write_exception',
            'message' => $exception->getMessage(),
            'database_file' => basename(DB_FILE),
        ]);
        return 'exception';
    }
}

function archiveTaskById(string $taskId): string
{
    writeDebugLog('task_archive_submit', [
        'task_id' => $taskId,
    ], 'started', [
        'request_method' => 'POST',
    ]);

    try {
        $pdo = getDatabaseConnection();
        $existingStatement = $pdo->prepare('SELECT id, title, status, archived_at FROM tasks WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $existingStatement->execute([':id' => $taskId]);
        $task = $existingStatement->fetch();
        $existingStatement->closeCursor();

        if (!is_array($task)) {
            writeDebugLog('task_archive_not_found', [
                'task_id' => $taskId,
            ], 'failed', [
                'reason' => $taskId === '' ? 'empty_task_id' : 'missing_task',
                'database_write_blocked' => true,
            ]);
            return 'not_found';
        }

        $previousStatus = normalizeTaskStatus((string) ($task['status'] ?? '未开始'));
        $currentArchivedAt = isset($task['archived_at']) && is_string($task['archived_at']) ? trim($task['archived_at']) : '';
        if ($previousStatus === '已归档' || $currentArchivedAt !== '') {
            writeDebugLog('task_archive_duplicate_blocked', [
                'task_id' => $taskId,
                'previous_status' => $previousStatus,
                'archived_at' => $currentArchivedAt,
            ], 'failed', [
                'reason' => 'already_archived',
                'database_write_blocked' => true,
                'title_length' => stringLength((string) ($task['title'] ?? '')),
            ]);
            return 'already_archived';
        }

        $archivedAt = date('Y-m-d H:i:s');
        $updateStatement = $pdo->prepare(
            "UPDATE tasks
            SET status = '已归档',
                archived_at = :archived_at,
                archive_previous_status = :archive_previous_status,
                updated_at = :updated_at
            WHERE id = :id
                AND deleted_at IS NULL"
        );
        $updateStatement->execute([
            ':archived_at' => $archivedAt,
            ':archive_previous_status' => $previousStatus,
            ':updated_at' => $archivedAt,
            ':id' => $taskId,
        ]);
        $changedRows = $updateStatement->rowCount();
        $updateStatement->closeCursor();

        writeDebugLog('task_archive_success', [
            'task_id' => $taskId,
            'previous_status' => $previousStatus,
            'new_status' => '已归档',
        ], 'success', [
            'archived_at' => $archivedAt,
            'archive_previous_status' => $previousStatus,
            'was_completed' => $previousStatus === '已完成',
            'was_unfinished' => $previousStatus !== '已完成',
            'changed_rows' => $changedRows,
            'database_file' => basename(DB_FILE),
        ]);

        $historyWritten = recordTaskHistory($taskId, 'archive', buildTaskFieldChanges([
            'status' => $previousStatus,
            'archived_at' => $currentArchivedAt,
            'archive_previous_status' => '',
        ], [
            'status' => '已归档',
            'archived_at' => $archivedAt,
            'archive_previous_status' => $previousStatus,
        ], ['status', 'archived_at', 'archive_previous_status']), 'success', [
            'changed_rows' => $changedRows,
            'updated_at' => $archivedAt,
        ]);
        if (!$historyWritten) {
            writeDebugLog('task_archive_history_warning', [
                'task_id' => $taskId,
                'operation_type' => 'archive',
            ], 'failed', [
                'reason' => 'history_write_failed_after_archive',
                'message' => getLastTaskHistoryError(),
                'main_operation_kept' => true,
            ]);
        }

        return 'success';
    } catch (Throwable $exception) {
        writeDebugLog('task_archive_exception', [
            'task_id' => $taskId,
        ], 'failed', [
            'reason' => 'database_write_exception',
            'message' => $exception->getMessage(),
            'database_file' => basename(DB_FILE),
        ]);
        return 'exception';
    }
}

function restoreArchivedTaskById(string $taskId): string
{
    writeDebugLog('task_archive_restore_submit', [
        'task_id' => $taskId,
    ], 'started', [
        'request_method' => 'POST',
    ]);

    try {
        $pdo = getDatabaseConnection();
        $existingStatement = $pdo->prepare('SELECT id, title, status, archived_at, archive_previous_status FROM tasks WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $existingStatement->execute([':id' => $taskId]);
        $task = $existingStatement->fetch();
        $existingStatement->closeCursor();

        if (!is_array($task)) {
            writeDebugLog('task_archive_restore_not_found', [
                'task_id' => $taskId,
            ], 'failed', [
                'reason' => $taskId === '' ? 'empty_task_id' : 'missing_task',
                'database_write_blocked' => true,
            ]);
            return 'not_found';
        }

        $currentStatus = normalizeTaskStatus((string) ($task['status'] ?? '未开始'));
        $currentArchivedAt = isset($task['archived_at']) && is_string($task['archived_at']) ? trim($task['archived_at']) : '';
        if ($currentStatus !== '已归档' && $currentArchivedAt === '') {
            writeDebugLog('task_archive_restore_not_archived', [
                'task_id' => $taskId,
                'current_status' => $currentStatus,
            ], 'failed', [
                'reason' => 'task_not_archived',
                'database_write_blocked' => true,
                'title_length' => stringLength((string) ($task['title'] ?? '')),
            ]);
            return 'not_archived';
        }

        $storedPreviousStatus = isset($task['archive_previous_status']) && is_string($task['archive_previous_status'])
            ? trim($task['archive_previous_status'])
            : '';
        $restoredStatus = $storedPreviousStatus !== '' && normalizeTaskStatus($storedPreviousStatus) !== '已归档'
            ? normalizeTaskStatus($storedPreviousStatus)
            : '未开始';
        $updatedAt = date('Y-m-d H:i:s');
        $updateStatement = $pdo->prepare(
            'UPDATE tasks
            SET status = :status,
                archived_at = NULL,
                archive_previous_status = NULL,
                updated_at = :updated_at
            WHERE id = :id
                AND deleted_at IS NULL'
        );
        $updateStatement->execute([
            ':status' => $restoredStatus,
            ':updated_at' => $updatedAt,
            ':id' => $taskId,
        ]);
        $changedRows = $updateStatement->rowCount();
        $updateStatement->closeCursor();

        writeDebugLog('task_archive_restore_success', [
            'task_id' => $taskId,
            'previous_status' => $currentStatus,
            'restored_status' => $restoredStatus,
        ], 'success', [
            'archived_at_before_restore' => $currentArchivedAt,
            'updated_at' => $updatedAt,
            'changed_rows' => $changedRows,
            'database_file' => basename(DB_FILE),
        ]);

        $historyWritten = recordTaskHistory($taskId, 'restore_archive', buildTaskFieldChanges([
            'status' => $currentStatus,
            'archived_at' => $currentArchivedAt,
            'archive_previous_status' => $storedPreviousStatus,
        ], [
            'status' => $restoredStatus,
            'archived_at' => '',
            'archive_previous_status' => '',
        ], ['status', 'archived_at', 'archive_previous_status']), 'success', [
            'changed_rows' => $changedRows,
            'updated_at' => $updatedAt,
        ]);
        if (!$historyWritten) {
            writeDebugLog('task_archive_restore_history_warning', [
                'task_id' => $taskId,
                'operation_type' => 'restore_archive',
            ], 'failed', [
                'reason' => 'history_write_failed_after_archive_restore',
                'message' => getLastTaskHistoryError(),
                'main_operation_kept' => true,
            ]);
        }

        return 'success';
    } catch (Throwable $exception) {
        writeDebugLog('task_archive_restore_exception', [
            'task_id' => $taskId,
        ], 'failed', [
            'reason' => 'database_write_exception',
            'message' => $exception->getMessage(),
            'database_file' => basename(DB_FILE),
        ]);
        return 'exception';
    }
}

function deleteTaskById(string $taskId): string
{
    writeDebugLog('task_trash_move_submit', [
        'task_id' => $taskId,
    ], 'started', [
        'request_method' => 'POST',
        'operation_type' => 'move_to_trash',
    ]);

    try {
        $pdo = getDatabaseConnection();
        $existingStatement = $pdo->prepare('SELECT id, title, status FROM tasks WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $existingStatement->execute([':id' => $taskId]);
        $deletedTask = $existingStatement->fetch();
        $existingStatement->closeCursor();

        if (!is_array($deletedTask)) {
            $countStatement = $pdo->query('SELECT COUNT(*) FROM tasks WHERE deleted_at IS NULL');
            $taskCount = $countStatement !== false ? (int) $countStatement->fetchColumn() : 0;
            writeDebugLog('task_delete_not_found', [
                'task_id' => $taskId,
            ], 'failed', [
                'reason' => $taskId === '' ? 'empty_task_id' : 'missing_task',
                'task_count' => $taskCount,
            ]);
            writeDebugLog('task_trash_move_not_found', [
                'task_id' => $taskId,
            ], 'failed', [
                'reason' => $taskId === '' ? 'empty_task_id' : 'missing_active_task',
                'operation_type' => 'move_to_trash',
                'database_write_blocked' => true,
            ]);
            return 'not_found';
        }

        $beforeCount = (int) $pdo->query('SELECT COUNT(*) FROM tasks WHERE deleted_at IS NULL')->fetchColumn();
        $beforeTrashCount = (int) $pdo->query("SELECT COUNT(*) FROM tasks WHERE deleted_at IS NOT NULL AND deleted_at <> ''")->fetchColumn();
        $deletedAt = date('Y-m-d H:i:s');
        $deleteStatement = $pdo->prepare(
            "UPDATE tasks
            SET deleted_at = :deleted_at,
                updated_at = :updated_at
            WHERE id = :id
                AND deleted_at IS NULL"
        );
        $deleteStatement->execute([
            ':deleted_at' => $deletedAt,
            ':updated_at' => $deletedAt,
            ':id' => $taskId,
        ]);
        $afterCount = (int) $pdo->query('SELECT COUNT(*) FROM tasks WHERE deleted_at IS NULL')->fetchColumn();
        $afterTrashCount = (int) $pdo->query("SELECT COUNT(*) FROM tasks WHERE deleted_at IS NOT NULL AND deleted_at <> ''")->fetchColumn();

        writeDebugLog('task_delete_success', [
            'task_id' => $taskId,
            'title_length' => stringLength((string) $deletedTask['title']),
            'status' => (string) $deletedTask['status'],
        ], 'success', [
            'before_count' => $beforeCount,
            'after_count' => $afterCount,
            'database_file' => basename(DB_FILE),
            'deleted_at' => $deletedAt,
        ]);
        writeDebugLog('task_trash_move_success', [
            'task_id' => $taskId,
            'title_length' => stringLength((string) $deletedTask['title']),
            'status' => (string) $deletedTask['status'],
        ], 'success', [
            'operation_type' => 'move_to_trash',
            'deleted_at' => $deletedAt,
            'active_count_before' => $beforeCount,
            'active_count_after' => $afterCount,
            'trash_count_before' => $beforeTrashCount,
            'trash_count_after' => $afterTrashCount,
            'changed_rows' => $deleteStatement->rowCount(),
            'database_file' => basename(DB_FILE),
        ]);

        $historyWritten = recordTaskHistory($taskId, 'delete', buildTaskFieldChanges([
            'deleted_at' => '',
        ], [
            'deleted_at' => $deletedAt,
        ], ['deleted_at']), 'success', [
            'operation_type' => 'move_to_trash',
            'changed_rows' => $deleteStatement->rowCount(),
            'active_count_before' => $beforeCount,
            'active_count_after' => $afterCount,
            'trash_count_before' => $beforeTrashCount,
            'trash_count_after' => $afterTrashCount,
        ]);
        if (!$historyWritten) {
            writeDebugLog('task_delete_history_warning', [
                'task_id' => $taskId,
                'operation_type' => 'delete',
            ], 'failed', [
                'reason' => 'history_write_failed_after_delete',
                'message' => getLastTaskHistoryError(),
                'main_operation_kept' => true,
            ]);
        }

        return 'success';
    } catch (Throwable $exception) {
        writeDebugLog('task_delete_exception', [
            'task_id' => $taskId,
        ], 'failed', [
            'reason' => 'database_write_exception',
            'message' => $exception->getMessage(),
            'database_file' => basename(DB_FILE),
        ]);
        writeDebugLog('task_trash_move_exception', [
            'task_id' => $taskId,
        ], 'failed', [
            'reason' => 'database_write_exception',
            'operation_type' => 'move_to_trash',
            'message' => $exception->getMessage(),
            'database_file' => basename(DB_FILE),
        ]);
        return 'exception';
    }
}

function restoreDeletedTaskById(string $taskId): string
{
    writeDebugLog('task_trash_restore_submit', [
        'task_id' => $taskId,
    ], 'started', [
        'request_method' => 'POST',
        'operation_type' => 'restore_from_trash',
    ]);

    try {
        $pdo = getDatabaseConnection();
        $existingStatement = $pdo->prepare(
            "SELECT id, title, status, priority, category_id, due_at, archived_at, archive_previous_status, deleted_at, created_at, updated_at
            FROM tasks
            WHERE id = :id
                AND deleted_at IS NOT NULL
                AND deleted_at <> ''
            LIMIT 1"
        );
        $existingStatement->execute([':id' => $taskId]);
        $deletedTask = $existingStatement->fetch();
        $existingStatement->closeCursor();

        if (!is_array($deletedTask)) {
            writeDebugLog('task_trash_restore_not_found', [
                'task_id' => $taskId,
            ], 'failed', [
                'reason' => $taskId === '' ? 'empty_task_id' : 'missing_trash_task',
                'operation_type' => 'restore_from_trash',
                'database_write_blocked' => true,
            ]);
            return 'not_found';
        }

        $activeCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM tasks WHERE deleted_at IS NULL')->fetchColumn();
        $trashCountBefore = (int) $pdo->query("SELECT COUNT(*) FROM tasks WHERE deleted_at IS NOT NULL AND deleted_at <> ''")->fetchColumn();
        $updatedAt = date('Y-m-d H:i:s');
        $restoreStatement = $pdo->prepare(
            'UPDATE tasks
            SET deleted_at = NULL,
                updated_at = :updated_at
            WHERE id = :id
                AND deleted_at IS NOT NULL
                AND deleted_at <> :empty_deleted_at'
        );
        $restoreStatement->execute([
            ':updated_at' => $updatedAt,
            ':id' => $taskId,
            ':empty_deleted_at' => '',
        ]);
        $changedRows = $restoreStatement->rowCount();
        $restoreStatement->closeCursor();

        if ($changedRows < 1) {
            writeDebugLog('task_trash_restore_exception', [
                'task_id' => $taskId,
            ], 'failed', [
                'reason' => 'restore_update_affected_zero_rows',
                'operation_type' => 'restore_from_trash',
                'deleted_at_before_restore' => (string) ($deletedTask['deleted_at'] ?? ''),
                'database_file' => basename(DB_FILE),
            ]);
            return 'exception';
        }

        $activeCountAfter = (int) $pdo->query('SELECT COUNT(*) FROM tasks WHERE deleted_at IS NULL')->fetchColumn();
        $trashCountAfter = (int) $pdo->query("SELECT COUNT(*) FROM tasks WHERE deleted_at IS NOT NULL AND deleted_at <> ''")->fetchColumn();

        writeDebugLog('task_trash_restore_success', [
            'task_id' => $taskId,
            'title_length' => stringLength((string) $deletedTask['title']),
            'status' => normalizeTaskStatus((string) ($deletedTask['status'] ?? '未开始')),
            'priority' => normalizeTaskPriority((string) ($deletedTask['priority'] ?? DEFAULT_TASK_PRIORITY)),
            'category_id' => isset($deletedTask['category_id']) && is_string($deletedTask['category_id']) ? trim($deletedTask['category_id']) : '',
        ], 'success', [
            'operation_type' => 'restore_from_trash',
            'deleted_at_before_restore' => (string) ($deletedTask['deleted_at'] ?? ''),
            'updated_at' => $updatedAt,
            'active_count_before' => $activeCountBefore,
            'active_count_after' => $activeCountAfter,
            'trash_count_before' => $trashCountBefore,
            'trash_count_after' => $trashCountAfter,
            'changed_rows' => $changedRows,
            'database_file' => basename(DB_FILE),
        ]);

        $historyWritten = recordTaskHistory($taskId, 'restore_trash', buildTaskFieldChanges([
            'deleted_at' => (string) ($deletedTask['deleted_at'] ?? ''),
        ], [
            'deleted_at' => '',
        ], ['deleted_at']), 'success', [
            'operation_type' => 'restore_from_trash',
            'changed_rows' => $changedRows,
            'updated_at' => $updatedAt,
            'active_count_before' => $activeCountBefore,
            'active_count_after' => $activeCountAfter,
            'trash_count_before' => $trashCountBefore,
            'trash_count_after' => $trashCountAfter,
        ]);
        if (!$historyWritten) {
            writeDebugLog('task_trash_restore_history_warning', [
                'task_id' => $taskId,
                'operation_type' => 'restore_trash',
            ], 'failed', [
                'reason' => 'history_write_failed_after_trash_restore',
                'message' => getLastTaskHistoryError(),
                'main_operation_kept' => true,
            ]);
        }

        return 'success';
    } catch (Throwable $exception) {
        writeDebugLog('task_trash_restore_exception', [
            'task_id' => $taskId,
        ], 'failed', [
            'reason' => 'database_write_exception',
            'operation_type' => 'restore_from_trash',
            'message' => $exception->getMessage(),
            'database_file' => basename(DB_FILE),
        ]);
        return 'exception';
    }
}

function permanentlyDeleteTaskById(string $taskId, string $confirmation): string
{
    writeDebugLog('task_trash_permanent_delete_submit', [
        'task_id' => $taskId,
        'confirmation' => $confirmation,
    ], 'started', [
        'request_method' => 'POST',
        'operation_type' => 'permanent_delete',
    ]);

    if ($confirmation !== 'yes') {
        writeDebugLog('task_trash_permanent_delete_blocked', [
            'task_id' => $taskId,
            'confirmation' => $confirmation,
        ], 'failed', [
            'reason' => 'confirmation_missing',
            'operation_type' => 'permanent_delete',
            'database_write_blocked' => true,
        ]);
        return 'confirmation_missing';
    }

    try {
        $pdo = getDatabaseConnection();
        $existingStatement = $pdo->prepare(
            "SELECT id, title, status, priority, deleted_at
            FROM tasks
            WHERE id = :id
                AND deleted_at IS NOT NULL
                AND deleted_at <> ''
            LIMIT 1"
        );
        $existingStatement->execute([':id' => $taskId]);
        $deletedTask = $existingStatement->fetch();
        $existingStatement->closeCursor();

        if (!is_array($deletedTask)) {
            writeDebugLog('task_trash_permanent_delete_not_found', [
                'task_id' => $taskId,
            ], 'failed', [
                'reason' => $taskId === '' ? 'empty_task_id' : 'missing_trash_task',
                'operation_type' => 'permanent_delete',
                'database_write_blocked' => true,
            ]);
            return 'not_found';
        }

        $trashCountBefore = (int) $pdo->query("SELECT COUNT(*) FROM tasks WHERE deleted_at IS NOT NULL AND deleted_at <> ''")->fetchColumn();
        $pdo->beginTransaction();
        $deleteStatement = $pdo->prepare(
            "DELETE FROM tasks
            WHERE id = :id
                AND deleted_at IS NOT NULL
                AND deleted_at <> :empty_deleted_at"
        );
        $deleteStatement->execute([
            ':id' => $taskId,
            ':empty_deleted_at' => '',
        ]);
        $changedRows = $deleteStatement->rowCount();
        $deleteStatement->closeCursor();

        if ($changedRows < 1) {
            $pdo->rollBack();
            writeDebugLog('task_trash_permanent_delete_exception', [
                'task_id' => $taskId,
            ], 'failed', [
                'reason' => 'delete_affected_zero_rows',
                'operation_type' => 'permanent_delete',
                'database_file' => basename(DB_FILE),
            ]);
            return 'exception';
        }

        $pdo->commit();
        $trashCountAfter = (int) $pdo->query("SELECT COUNT(*) FROM tasks WHERE deleted_at IS NOT NULL AND deleted_at <> ''")->fetchColumn();

        writeDebugLog('task_trash_permanent_delete_success', [
            'task_id' => $taskId,
            'title_length' => stringLength((string) $deletedTask['title']),
            'status' => normalizeTaskStatus((string) ($deletedTask['status'] ?? '未开始')),
            'priority' => normalizeTaskPriority((string) ($deletedTask['priority'] ?? DEFAULT_TASK_PRIORITY)),
        ], 'success', [
            'operation_type' => 'permanent_delete',
            'deleted_at_before_permanent_delete' => (string) ($deletedTask['deleted_at'] ?? ''),
            'trash_count_before' => $trashCountBefore,
            'trash_count_after' => $trashCountAfter,
            'changed_rows' => $changedRows,
            'database_file' => basename(DB_FILE),
        ]);

        $historyWritten = recordTaskHistory($taskId, 'permanent_delete', buildTaskFieldChanges([
            'deleted_at' => (string) ($deletedTask['deleted_at'] ?? ''),
        ], [
            'deleted_at' => 'permanently_deleted',
        ], ['deleted_at']), 'success', [
            'operation_type' => 'permanent_delete',
            'changed_rows' => $changedRows,
            'trash_count_before' => $trashCountBefore,
            'trash_count_after' => $trashCountAfter,
        ]);
        if (!$historyWritten) {
            writeDebugLog('task_trash_permanent_delete_history_warning', [
                'task_id' => $taskId,
                'operation_type' => 'permanent_delete',
            ], 'failed', [
                'reason' => 'history_write_failed_after_permanent_delete',
                'message' => getLastTaskHistoryError(),
                'main_operation_kept' => true,
            ]);
        }

        return 'success';
    } catch (Throwable $exception) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        writeDebugLog('task_trash_permanent_delete_exception', [
            'task_id' => $taskId,
        ], 'failed', [
            'reason' => 'database_write_exception',
            'operation_type' => 'permanent_delete',
            'message' => $exception->getMessage(),
            'database_file' => basename(DB_FILE),
        ]);
        return 'exception';
    }
}

function normalizeSubmittedBulkTaskIds($submittedTaskIds): array
{
    $normalizedTaskIds = [];
    $seenTaskIds = [];

    if (!is_array($submittedTaskIds)) {
        return [];
    }

    foreach ($submittedTaskIds as $submittedTaskId) {
        if (!is_string($submittedTaskId)) {
            continue;
        }

        $taskId = trim($submittedTaskId);
        if ($taskId === '' || isset($seenTaskIds[$taskId])) {
            continue;
        }

        $seenTaskIds[$taskId] = true;
        $normalizedTaskIds[] = $taskId;
    }

    return $normalizedTaskIds;
}

function getBulkActionLabel(string $bulkAction): string
{
    $labels = [
        'complete' => '批量完成',
        'archive' => '批量归档',
        'delete' => '批量删除',
        'category' => '批量修改分类',
        'priority' => '批量修改优先级',
    ];

    return $labels[$bulkAction] ?? '批量操作';
}

function getBulkFailureReasonLabel(string $reason): string
{
    $labels = [
        'empty_task_id' => '空任务 ID',
        'missing_task' => '任务不存在或已删除',
        'invalid_action' => '批量操作类型无效',
        'invalid_category' => '分类无效',
        'invalid_priority' => '优先级无效',
        'already_archived' => '任务已归档',
        'task_not_archived' => '任务未归档',
        'database_write_exception' => '数据库写入失败',
        'status_outside_allowed_enum' => '目标状态无效',
        'unknown_result' => '未知处理结果',
    ];

    return $labels[$reason] ?? $reason;
}

function updateTaskCategoryById(string $taskId, string $categoryId): string
{
    try {
        $pdo = getDatabaseConnection();
        $existingStatement = $pdo->prepare('SELECT id, title, category_id FROM tasks WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $existingStatement->execute([':id' => $taskId]);
        $existingTask = $existingStatement->fetch();
        $existingStatement->closeCursor();

        if (!is_array($existingTask)) {
            writeDebugLog('task_bulk_category_not_found', [
                'task_id' => $taskId,
                'category_id' => $categoryId,
            ], 'failed', [
                'reason' => $taskId === '' ? 'empty_task_id' : 'missing_task',
            ]);
            return 'not_found';
        }

        $updatedAt = date('Y-m-d H:i:s');
        $previousCategoryId = isset($existingTask['category_id']) && is_string($existingTask['category_id']) ? trim($existingTask['category_id']) : '';
        $updateStatement = $pdo->prepare(
            'UPDATE tasks
            SET category_id = :category_id,
                updated_at = :updated_at
            WHERE id = :id
                AND deleted_at IS NULL'
        );
        $updateStatement->execute([
            ':category_id' => $categoryId !== '' ? $categoryId : null,
            ':updated_at' => $updatedAt,
            ':id' => $taskId,
        ]);
        $changedRows = $updateStatement->rowCount();
        $updateStatement->closeCursor();

        writeDebugLog('task_bulk_category_update_success', [
            'task_id' => $taskId,
            'previous_category_id' => $previousCategoryId,
            'category_id' => $categoryId,
        ], 'success', [
            'title_length' => stringLength((string) $existingTask['title']),
            'changed' => $previousCategoryId !== $categoryId,
            'changed_rows' => $changedRows,
            'updated_at' => $updatedAt,
            'database_file' => basename(DB_FILE),
        ]);

        $historyWritten = recordTaskHistory($taskId, 'bulk_category_update', buildTaskFieldChanges([
            'category_id' => $previousCategoryId,
        ], [
            'category_id' => $categoryId,
        ], ['category_id']), 'success', [
            'changed_rows' => $changedRows,
            'updated_at' => $updatedAt,
            'changed' => $previousCategoryId !== $categoryId,
        ]);
        if (!$historyWritten) {
            writeDebugLog('task_bulk_category_history_warning', [
                'task_id' => $taskId,
                'operation_type' => 'bulk_category_update',
            ], 'failed', [
                'reason' => 'history_write_failed_after_bulk_category_update',
                'message' => getLastTaskHistoryError(),
                'main_operation_kept' => true,
            ]);
        }

        return 'success';
    } catch (Throwable $exception) {
        writeDebugLog('task_bulk_category_update_exception', [
            'task_id' => $taskId,
            'category_id' => $categoryId,
        ], 'failed', [
            'reason' => 'database_write_exception',
            'message' => $exception->getMessage(),
            'database_file' => basename(DB_FILE),
        ]);
        return 'exception';
    }
}

function updateTaskPriorityById(string $taskId, string $priority): string
{
    if (!isAllowedTaskPriority($priority)) {
        writeDebugLog('task_bulk_priority_invalid_submit', [
            'task_id' => $taskId,
            'submitted_priority' => $priority,
        ], 'failed', [
            'reason' => 'priority_outside_allowed_enum',
            'allowed_priorities' => ALLOWED_PRIORITIES,
        ]);
        return 'invalid_priority';
    }

    try {
        $pdo = getDatabaseConnection();
        $existingStatement = $pdo->prepare('SELECT id, title, priority FROM tasks WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $existingStatement->execute([':id' => $taskId]);
        $existingTask = $existingStatement->fetch();
        $existingStatement->closeCursor();

        if (!is_array($existingTask)) {
            writeDebugLog('task_bulk_priority_not_found', [
                'task_id' => $taskId,
                'priority' => $priority,
            ], 'failed', [
                'reason' => $taskId === '' ? 'empty_task_id' : 'missing_task',
            ]);
            return 'not_found';
        }

        $updatedAt = date('Y-m-d H:i:s');
        $previousPriority = normalizeTaskPriority((string) ($existingTask['priority'] ?? DEFAULT_TASK_PRIORITY));
        $updateStatement = $pdo->prepare(
            'UPDATE tasks
            SET priority = :priority,
                updated_at = :updated_at
            WHERE id = :id
                AND deleted_at IS NULL'
        );
        $updateStatement->execute([
            ':priority' => $priority,
            ':updated_at' => $updatedAt,
            ':id' => $taskId,
        ]);
        $changedRows = $updateStatement->rowCount();
        $updateStatement->closeCursor();

        writeDebugLog('task_bulk_priority_update_success', [
            'task_id' => $taskId,
            'previous_priority' => $previousPriority,
            'priority' => $priority,
        ], 'success', [
            'title_length' => stringLength((string) $existingTask['title']),
            'changed' => $previousPriority !== $priority,
            'changed_rows' => $changedRows,
            'updated_at' => $updatedAt,
            'database_file' => basename(DB_FILE),
        ]);

        $historyWritten = recordTaskHistory($taskId, 'bulk_priority_update', buildTaskFieldChanges([
            'priority' => $previousPriority,
        ], [
            'priority' => $priority,
        ], ['priority']), 'success', [
            'changed_rows' => $changedRows,
            'updated_at' => $updatedAt,
            'changed' => $previousPriority !== $priority,
        ]);
        if (!$historyWritten) {
            writeDebugLog('task_bulk_priority_history_warning', [
                'task_id' => $taskId,
                'operation_type' => 'bulk_priority_update',
            ], 'failed', [
                'reason' => 'history_write_failed_after_bulk_priority_update',
                'message' => getLastTaskHistoryError(),
                'main_operation_kept' => true,
            ]);
        }

        return 'success';
    } catch (Throwable $exception) {
        writeDebugLog('task_bulk_priority_update_exception', [
            'task_id' => $taskId,
            'priority' => $priority,
        ], 'failed', [
            'reason' => 'database_write_exception',
            'message' => $exception->getMessage(),
            'database_file' => basename(DB_FILE),
        ]);
        return 'exception';
    }
}

function executeBulkTaskOperation(string $bulkAction, array $taskIds, string $targetCategoryId, string $targetPriority): array
{
    $allowedBulkActions = ['complete', 'archive', 'delete', 'category', 'priority'];
    $startedAt = date('Y-m-d H:i:s');
    $summary = [
        'action' => $bulkAction,
        'label' => getBulkActionLabel($bulkAction),
        'selected_count' => count($taskIds),
        'success_count' => 0,
        'failed_count' => 0,
        'successes' => [],
        'failures' => [],
        'failure_reasons' => [],
        'status' => 'failed',
        'message' => '',
    ];

    writeDebugLog('task_bulk_operation_submit', [
        'bulk_action' => $bulkAction,
        'selected_count' => count($taskIds),
        'task_ids' => $taskIds,
        'target_category_id' => $targetCategoryId,
        'target_priority' => $targetPriority,
    ], 'started', [
        'request_method' => 'POST',
        'started_at' => $startedAt,
    ]);

    if (!in_array($bulkAction, $allowedBulkActions, true)) {
        $summary['message'] = '批量操作类型无效，系统已拦截该请求。';
        $summary['failure_reasons']['invalid_action'] = count($taskIds);
        writeDebugLog('task_bulk_operation_validation_failed', [
            'bulk_action' => $bulkAction,
            'selected_count' => count($taskIds),
        ], 'failed', [
            'reason' => 'invalid_action',
            'allowed_bulk_actions' => $allowedBulkActions,
            'database_write_blocked' => true,
        ]);
        return $summary;
    }

    if ($taskIds === []) {
        $summary['message'] = '未选择任务，批量操作未执行。';
        writeDebugLog('task_bulk_operation_validation_failed', [
            'bulk_action' => $bulkAction,
            'selected_count' => 0,
        ], 'failed', [
            'reason' => 'empty_selection',
            'database_write_blocked' => true,
        ]);
        return $summary;
    }

    if ($bulkAction === 'category' && $targetCategoryId !== '') {
        $categories = loadCategories();
        if (findCategoryById($categories, $targetCategoryId) === null) {
            $summary['message'] = '目标分类不存在，批量修改分类未执行。';
            $summary['failed_count'] = count($taskIds);
            $summary['failure_reasons']['invalid_category'] = count($taskIds);
            foreach ($taskIds as $taskId) {
                $summary['failures'][] = [
                    'task_id' => $taskId,
                    'reason' => 'invalid_category',
                    'message' => getBulkFailureReasonLabel('invalid_category'),
                ];
            }
            writeDebugLog('task_bulk_operation_validation_failed', [
                'bulk_action' => $bulkAction,
                'selected_count' => count($taskIds),
                'target_category_id' => $targetCategoryId,
            ], 'failed', [
                'reason' => 'invalid_category',
                'database_write_blocked' => true,
            ]);
            return $summary;
        }
    }

    if ($bulkAction === 'priority' && !isAllowedTaskPriority($targetPriority)) {
        $summary['message'] = '目标优先级无效，批量修改优先级未执行。';
        $summary['failed_count'] = count($taskIds);
        $summary['failure_reasons']['invalid_priority'] = count($taskIds);
        foreach ($taskIds as $taskId) {
            $summary['failures'][] = [
                'task_id' => $taskId,
                'reason' => 'invalid_priority',
                'message' => getBulkFailureReasonLabel('invalid_priority'),
            ];
        }
        writeDebugLog('task_bulk_operation_validation_failed', [
            'bulk_action' => $bulkAction,
            'selected_count' => count($taskIds),
            'target_priority' => $targetPriority,
        ], 'failed', [
            'reason' => 'invalid_priority',
            'allowed_priorities' => ALLOWED_PRIORITIES,
            'database_write_blocked' => true,
        ]);
        return $summary;
    }

    foreach ($taskIds as $taskId) {
        writeDebugLog('task_bulk_operation_item_start', [
            'bulk_action' => $bulkAction,
            'task_id' => $taskId,
            'target_category_id' => $targetCategoryId,
            'target_priority' => $targetPriority,
        ], 'started', [
            'started_at' => date('Y-m-d H:i:s'),
        ]);

        if ($bulkAction === 'complete') {
            $itemResult = updateTaskStatus($taskId, '已完成');
        } elseif ($bulkAction === 'archive') {
            $itemResult = archiveTaskById($taskId);
        } elseif ($bulkAction === 'delete') {
            $itemResult = deleteTaskById($taskId);
        } elseif ($bulkAction === 'category') {
            $itemResult = updateTaskCategoryById($taskId, $targetCategoryId);
        } else {
            $itemResult = updateTaskPriorityById($taskId, $targetPriority);
        }

        if ($itemResult === 'success') {
            $summary['success_count']++;
            $summary['successes'][] = [
                'task_id' => $taskId,
                'result' => 'success',
            ];
            writeDebugLog('task_bulk_operation_item_result', [
                'bulk_action' => $bulkAction,
                'task_id' => $taskId,
                'result' => $itemResult,
            ], 'success', [
                'completed_at' => date('Y-m-d H:i:s'),
            ]);
            continue;
        }

        if ($itemResult === 'not_found') {
            $reason = 'missing_task';
        } elseif ($itemResult === 'invalid_status') {
            $reason = 'status_outside_allowed_enum';
        } elseif ($itemResult === 'invalid_priority') {
            $reason = 'invalid_priority';
        } elseif ($itemResult === 'already_archived') {
            $reason = 'already_archived';
        } elseif ($itemResult === 'not_archived') {
            $reason = 'task_not_archived';
        } elseif ($itemResult === 'exception') {
            $reason = 'database_write_exception';
        } else {
            $reason = 'unknown_result';
        }
        $summary['failed_count']++;
        $summary['failure_reasons'][$reason] = ($summary['failure_reasons'][$reason] ?? 0) + 1;
        $summary['failures'][] = [
            'task_id' => $taskId,
            'reason' => $reason,
            'message' => getBulkFailureReasonLabel($reason),
        ];
        writeDebugLog('task_bulk_operation_item_result', [
            'bulk_action' => $bulkAction,
            'task_id' => $taskId,
            'result' => $itemResult,
        ], 'failed', [
            'reason' => $reason,
            'completed_at' => date('Y-m-d H:i:s'),
        ]);
    }

    if ($summary['success_count'] > 0 && $summary['failed_count'] > 0) {
        $summary['status'] = 'partial';
        $summary['message'] = $summary['label'] . '部分完成：成功 ' . $summary['success_count'] . ' 条，失败 ' . $summary['failed_count'] . ' 条。';
    } elseif ($summary['success_count'] > 0) {
        $summary['status'] = 'success';
        $summary['message'] = $summary['label'] . '成功：共处理 ' . $summary['success_count'] . ' 条任务。';
    } else {
        $summary['status'] = 'failed';
        $summary['message'] = $summary['label'] . '未完成：' . $summary['failed_count'] . ' 条任务处理失败。';
    }

    writeDebugLog('task_bulk_operation_completed', [
        'bulk_action' => $bulkAction,
        'selected_count' => $summary['selected_count'],
        'success_count' => $summary['success_count'],
        'failed_count' => $summary['failed_count'],
        'failure_reasons' => $summary['failure_reasons'],
    ], $summary['status'] === 'success' ? 'success' : ($summary['status'] === 'partial' ? 'partial' : 'failed'), [
        'successes' => $summary['successes'],
        'failures' => $summary['failures'],
        'completed_at' => date('Y-m-d H:i:s'),
    ]);

    return $summary;
}

function formatDateTime(string $createdAt): string
{
    $timestamp = strtotime($createdAt);
    if ($timestamp === false) {
        return escapeHtml($createdAt);
    }

    return date('Y-m-d H:i', $timestamp);
}

function createSummary(string $content): string
{
    $plainContent = trim(preg_replace('/\s+/u', ' ', $content) ?? '');
    if ($plainContent === '') {
        return '暂无内容摘要';
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($plainContent, 'UTF-8') > 90
            ? mb_substr($plainContent, 0, 90, 'UTF-8') . '...'
            : $plainContent;
    }

    return strlen($plainContent) > 180 ? substr($plainContent, 0, 180) . '...' : $plainContent;
}

$formValues = [
    'title' => '',
    'content' => '',
    'status' => '未开始',
    'priority' => DEFAULT_TASK_PRIORITY,
    'category_id' => '',
    'due_at' => '',
    'remind_at' => '',
    'tag_ids' => [],
];
$categoryFormValues = [
    'id' => '',
    'name' => '',
];
$categoryFormErrors = [];
$categoryErrorMessage = '';
$formErrors = [];
$successMessage = '';
if (isset($_GET['created']) && $_GET['created'] === '1') {
    $successMessage = '任务创建成功。';
}
if (isset($_GET['edited']) && $_GET['edited'] === '1') {
    $successMessage = '任务更新成功。';
}
if (isset($_GET['deleted']) && $_GET['deleted'] === '1') {
    $successMessage = '任务已移入回收站，可在回收站查看、恢复或永久删除。';
}
if (isset($_GET['trash_restored']) && $_GET['trash_restored'] === '1') {
    $successMessage = '任务已从回收站恢复到普通列表，原有标题、内容、状态、优先级、分类、标签和时间字段已保留。';
}
if (isset($_GET['permanently_deleted']) && $_GET['permanently_deleted'] === '1') {
    $successMessage = '任务已永久删除，普通列表和回收站均不可恢复。';
}
if (isset($_GET['archived']) && $_GET['archived'] === '1') {
    $successMessage = '任务已归档，可在归档列表中查看和恢复。';
}
if (isset($_GET['restored']) && $_GET['restored'] === '1') {
    $successMessage = '归档任务已恢复到普通列表。';
}
if (isset($_GET['status_changed']) && $_GET['status_changed'] === '1') {
    $successMessage = '任务状态已更新。';
}
if (isset($_GET['recurrence_created']) && $_GET['recurrence_created'] === '1') {
    $successMessage = '任务状态已更新，并已生成下一周期任务。';
}
if (isset($_GET['recurrence_skipped']) && $_GET['recurrence_skipped'] === '1') {
    $successMessage = '任务状态已更新；重复任务未生成下一周期，可能已到结束日期或规则不可用。';
}
if (isset($_GET['recurrence_duplicate']) && $_GET['recurrence_duplicate'] === '1') {
    $successMessage = '任务状态已更新；该周期已处理过，不会重复生成下一周期任务。';
}
if (isset($_GET['category_created']) && $_GET['category_created'] === '1') {
    $successMessage = '分类创建成功。';
}
if (isset($_GET['category_edited']) && $_GET['category_edited'] === '1') {
    $successMessage = '分类更新成功。';
}
if (isset($_GET['category_deleted']) && $_GET['category_deleted'] === '1') {
    $successMessage = '分类删除成功。';
}
if (isset($_GET['csv_import'])) {
    $csvSuccessCount = isset($_GET['success_count']) ? max(0, (int) $_GET['success_count']) : 0;
    $csvFailCount = isset($_GET['fail_count']) ? max(0, (int) $_GET['fail_count']) : 0;
    if ($csvFailCount > 0) {
        $csvFailuresText = '';
        if (isset($_GET['failures']) && is_string($_GET['failures']) && trim($_GET['failures']) !== '') {
            $failureParts = [];
            foreach (explode(',', trim($_GET['failures'])) as $failure) {
                $failure = trim(urldecode($failure));
                if ($failure !== '') {
                    $failureParts[] = $failure;
                }
            }
            if ($failureParts !== []) {
                $csvFailuresText = ' 失败原因：' . implode('；', $failureParts);
            }
        }
        $pageErrorMessage = 'CSV 导入部分完成，成功 ' . $csvSuccessCount . ' 条，失败 ' . $csvFailCount . ' 条。' . $csvFailuresText;
    } else {
        $successMessage = 'CSV 导入成功，已导入 ' . $csvSuccessCount . ' 条任务。';
    }
}
if (isset($_GET['restore_success']) && $_GET['restore_success'] === '1') {
    $restoreTaskCount = isset($_GET['task_count']) ? max(0, (int) $_GET['task_count']) : 0;
    $successMessage = '数据库恢复成功，已恢复 ' . $restoreTaskCount . ' 条任务。';
}
if (isset($_GET['restore_failed']) && $_GET['restore_failed'] === '1') {
    $pageErrorMessage = '数据库恢复失败，请稍后重试。';
}
$saveErrorMessage = '';
$pageErrorMessage = '';
if (isset($_GET['bulk_result']) && is_string($_GET['bulk_result'])) {
    $bulkResultStatus = trim($_GET['bulk_result']);
    $bulkActionLabel = isset($_GET['bulk_action']) && is_string($_GET['bulk_action'])
        ? getBulkActionLabel(trim($_GET['bulk_action']))
        : '批量操作';
    $bulkSuccessCount = isset($_GET['bulk_success']) ? max(0, (int) $_GET['bulk_success']) : 0;
    $bulkFailedCount = isset($_GET['bulk_failed']) ? max(0, (int) $_GET['bulk_failed']) : 0;
    $bulkReasonText = '';
    if (isset($_GET['bulk_reasons']) && is_string($_GET['bulk_reasons']) && trim($_GET['bulk_reasons']) !== '') {
        $reasonParts = [];
        foreach (explode(',', trim($_GET['bulk_reasons'])) as $reasonPair) {
            $reasonPair = trim($reasonPair);
            if ($reasonPair === '') {
                continue;
            }
            $segments = explode(':', $reasonPair, 2);
            $reasonKey = trim($segments[0] ?? '');
            $reasonCount = isset($segments[1]) ? max(0, (int) $segments[1]) : 0;
            if ($reasonKey !== '' && $reasonCount > 0) {
                $reasonParts[] = getBulkFailureReasonLabel($reasonKey) . ' ' . $reasonCount . ' 条';
            }
        }
        if ($reasonParts !== []) {
            $bulkReasonText = '失败原因：' . implode('；', $reasonParts) . '。';
        }
    }
    if ($bulkResultStatus === 'success') {
        $successMessage = $bulkActionLabel . '成功，已处理 ' . $bulkSuccessCount . ' 条任务。';
    } elseif ($bulkResultStatus === 'partial') {
        $pageErrorMessage = $bulkActionLabel . '部分完成，成功 ' . $bulkSuccessCount . ' 条，失败 ' . $bulkFailedCount . ' 条。' . $bulkReasonText;
    } elseif ($bulkResultStatus === 'failed') {
        $pageErrorMessage = $bulkActionLabel . '失败，成功 ' . $bulkSuccessCount . ' 条，失败 ' . $bulkFailedCount . ' 条。' . $bulkReasonText;
    } elseif ($bulkResultStatus === 'empty') {
        $pageErrorMessage = '未选择任务，批量操作未执行。';
    }
}
if (isset($_GET['delete_missing']) && $_GET['delete_missing'] === '1') {
    $pageErrorMessage = '未找到要删除的任务，任务可能已被删除。';
}
if (isset($_GET['trash_restore_missing']) && $_GET['trash_restore_missing'] === '1') {
    $pageErrorMessage = '未找到要恢复的回收站任务，任务可能已被永久删除。';
}
if (isset($_GET['trash_restore_failed']) && $_GET['trash_restore_failed'] === '1') {
    $pageErrorMessage = '回收站任务恢复失败，请稍后重试，系统已记录详细调试日志。';
}
if (isset($_GET['permanent_delete_missing']) && $_GET['permanent_delete_missing'] === '1') {
    $pageErrorMessage = '未找到要永久删除的回收站任务，任务可能已被处理。';
}
if (isset($_GET['permanent_delete_confirm_missing']) && $_GET['permanent_delete_confirm_missing'] === '1') {
    $pageErrorMessage = '永久删除缺少确认标记，系统已拦截该请求。';
}
if (isset($_GET['permanent_delete_failed']) && $_GET['permanent_delete_failed'] === '1') {
    $pageErrorMessage = '任务永久删除失败，请稍后重试，系统已记录详细调试日志。';
}
if (isset($_GET['archive_missing']) && $_GET['archive_missing'] === '1') {
    $pageErrorMessage = '未找到要归档的任务，任务可能已被删除。';
}
if (isset($_GET['archive_duplicate']) && $_GET['archive_duplicate'] === '1') {
    $pageErrorMessage = '任务已经归档，系统已拦截重复归档操作。';
}
if (isset($_GET['archive_failed']) && $_GET['archive_failed'] === '1') {
    $pageErrorMessage = '任务归档失败，请稍后重试，系统已记录详细日志。';
}
if (isset($_GET['restore_missing']) && $_GET['restore_missing'] === '1') {
    $pageErrorMessage = '未找到要恢复的归档任务，任务可能已被删除。';
}
if (isset($_GET['restore_not_archived']) && $_GET['restore_not_archived'] === '1') {
    $pageErrorMessage = '该任务不在归档列表中，系统已拦截恢复操作。';
}
if (isset($_GET['restore_failed']) && $_GET['restore_failed'] === '1') {
    $pageErrorMessage = '归档任务恢复失败，请稍后重试，系统已记录详细日志。';
}
if (isset($_GET['status_invalid']) && $_GET['status_invalid'] === '1') {
    $pageErrorMessage = '状态提交无效，系统已拦截该请求。';
}
if (isset($_GET['priority_invalid']) && $_GET['priority_invalid'] === '1') {
    $pageErrorMessage = '优先级提交无效，系统已拦截该请求。';
}
if (isset($_GET['recurrence_failed']) && $_GET['recurrence_failed'] === '1') {
    $pageErrorMessage = '任务状态已更新，但下一周期任务生成失败，系统已记录详细日志。';
}
if (isset($_GET['category_missing']) && $_GET['category_missing'] === '1') {
    $pageErrorMessage = '未找到要操作的分类，分类可能已被删除。';
}
if (isset($_GET['category_in_use']) && $_GET['category_in_use'] === '1') {
    $pageErrorMessage = '分类已被任务引用，删除操作已拦截。';
}
if (isset($_GET['tag_created']) && $_GET['tag_created'] === '1') {
    $successMessage = '标签创建成功。';
}
if (isset($_GET['tag_edited']) && $_GET['tag_edited'] === '1') {
    $successMessage = '标签更新成功。';
}
if (isset($_GET['tag_deleted']) && $_GET['tag_deleted'] === '1') {
    $successMessage = '标签删除成功。';
}
if (isset($_GET['tag_missing']) && $_GET['tag_missing'] === '1') {
    $pageErrorMessage = '未找到要操作的标签，标签可能已被删除。';
}
$tagErrorMessage = '';
$tagFormValues = ['id' => '', 'name' => '', 'color' => '#2563eb'];
$tagFormErrors = [];
$action = isset($_GET['action']) && is_string($_GET['action']) ? trim($_GET['action']) : '';
$requestedTaskId = isset($_GET['id']) && is_string($_GET['id']) ? trim(rawurldecode($_GET['id'])) : '';
$requestMethod = isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
$isEditRequest = $action === 'edit';
$isViewRequest = $action === 'view';
$isArchiveRequest = $action === 'archive';
$isTrashRequest = $action === 'trash';
$isEditMode = false;
$editTaskId = $isEditRequest ? $requestedTaskId : '';
$viewTask = null;
$tasks = loadTasks(TASK_VISIBILITY_ALL);
$activeTasks = loadTasks(TASK_VISIBILITY_ACTIVE);
$archivedTasks = loadTasks(TASK_VISIBILITY_ARCHIVED);
$trashTasks = loadTasks(TASK_VISIBILITY_TRASH);
$taskDashboardStats = loadTaskDashboardStats();
$calendarMonthInput = isset($_GET['calendar_month']) && is_string($_GET['calendar_month']) ? trim($_GET['calendar_month']) : '';
$calendarDateInput = isset($_GET['calendar_date']) && is_string($_GET['calendar_date']) ? trim($_GET['calendar_date']) : '';
$taskCalendarView = loadTaskCalendarView($calendarMonthInput, $calendarDateInput);
$categories = loadCategories();
$tags = loadTags();
$pdo = getDatabaseConnection();
$userSettings = getAllUserSettings($pdo);
$effectiveSortField = isset($userSettings[SETTING_KEY_DEFAULT_SORT_FIELD]) ? $userSettings[SETTING_KEY_DEFAULT_SORT_FIELD] : DEFAULT_SORT_FIELD;
$effectiveSortOrder = isset($userSettings[SETTING_KEY_DEFAULT_SORT_ORDER]) ? $userSettings[SETTING_KEY_DEFAULT_SORT_ORDER] : DEFAULT_SORT_ORDER;
$effectivePageSize = isset($userSettings[SETTING_KEY_DEFAULT_PAGE_SIZE]) && is_numeric($userSettings[SETTING_KEY_DEFAULT_PAGE_SIZE]) ? (int) $userSettings[SETTING_KEY_DEFAULT_PAGE_SIZE] : DEFAULT_PAGE_SIZE;
$effectivePriority = isset($userSettings[SETTING_KEY_DEFAULT_PRIORITY]) ? $userSettings[SETTING_KEY_DEFAULT_PRIORITY] : DEFAULT_TASK_PRIORITY;
$effectiveReminderLeadTime = isset($userSettings[SETTING_KEY_REMINDER_LEAD_TIME]) && is_numeric($userSettings[SETTING_KEY_REMINDER_LEAD_TIME]) ? (int) $userSettings[SETTING_KEY_REMINDER_LEAD_TIME] : DEFAULT_REMINDER_LEAD_TIME;
$settingsErrorMessage = '';
$settingsFormValues = [];
$settingsSavedMessage = isset($_GET['settings_saved']) ? '设置已保存。' : '';
$databaseErrorMessage = getDatabaseErrorMessage();
if ($databaseErrorMessage !== '') {
    $pageErrorMessage = $databaseErrorMessage;
}

if ($requestMethod === 'GET' && $isEditRequest) {
    writeDebugLog('task_edit_enter', [
        'task_id' => $editTaskId,
    ], 'started');

    $editingTask = $editTaskId !== '' ? findTaskById($tasks, $editTaskId) : null;
    if ($editingTask === null) {
        writeDebugLog('task_edit_not_found', [
            'task_id' => $editTaskId,
        ], 'failed', [
            'stage' => 'enter',
            'reason' => $editTaskId === '' ? 'empty_task_id' : 'missing_task',
        ]);
        $pageErrorMessage = '未找到要编辑的任务，任务可能已被删除。';
    } else {
        $isEditMode = true;
        $editingRepeatRule = isset($editingTask['repeat_rule']) && is_string($editingTask['repeat_rule']) ? trim($editingTask['repeat_rule']) : '';
        $parsedRepeatRule = parseRepeatRule($editingRepeatRule);
        $formValues = [
            'title' => $editingTask['title'],
            'content' => $editingTask['content'],
            'status' => normalizeTaskStatus($editingTask['status']),
            'priority' => normalizeTaskPriority((string) ($editingTask['priority'] ?? DEFAULT_TASK_PRIORITY)),
            'category_id' => isset($editingTask['category_id']) && is_string($editingTask['category_id']) ? trim($editingTask['category_id']) : '',
            'due_at' => formatDueAtForInput((string) ($editingTask['due_at'] ?? '')),
            'remind_at' => formatRemindAtForInput((string) ($editingTask['remind_at'] ?? '')),
            'repeat_rule' => $editingRepeatRule,
            'repeat_rule_type' => $parsedRepeatRule['type'],
            'repeat_rule_interval' => (string) $parsedRepeatRule['interval'],
            'repeat_rule_end_date' => $parsedRepeatRule['end_date'],
            'tag_ids' => isset($editingTask['tag_ids']) && is_array($editingTask['tag_ids']) ? $editingTask['tag_ids'] : [],
        ];
        writeDebugLog('task_edit_enter', [
            'task_id' => $editTaskId,
        ], 'success', [
            'title_length' => stringLength($editingTask['title']),
            'content_length' => stringLength($editingTask['content']),
            'status' => $editingTask['status'],
            'priority' => normalizeTaskPriority((string) ($editingTask['priority'] ?? DEFAULT_TASK_PRIORITY)),
            'category_id' => isset($editingTask['category_id']) && is_string($editingTask['category_id']) ? trim($editingTask['category_id']) : '',
            'due_at' => normalizeStoredDueAt($editingTask['due_at'] ?? ''),
            'remind_at' => normalizeStoredRemindAt($editingTask['remind_at'] ?? ''),
            'repeat_rule' => $editingRepeatRule,
            'repeat_rule_type' => $parsedRepeatRule['type'],
            'repeat_rule_interval' => $parsedRepeatRule['interval'],
            'repeat_rule_end_date' => $parsedRepeatRule['end_date'],
            'tag_ids' => $formValues['tag_ids'],
        ]);
    }
}

if ($requestMethod === 'GET' && $isViewRequest) {
    writeDebugLog('task_detail_view', [
        'task_id' => $requestedTaskId,
    ], 'started');

    $viewTask = $requestedTaskId !== '' ? findTaskById($tasks, $requestedTaskId) : null;
    $viewTaskSubtasks = [];
    $viewTaskComments = [];
    $viewTaskHistory = [];
    $viewTaskAttachments = [];
    $viewTaskRecurrences = [];
    $commentErrorMessage = '';
    $attachmentErrorMessage = '';

    if ($viewTask === null) {
        writeDebugLog('task_detail_not_found', [
            'task_id' => $requestedTaskId,
        ], 'failed', [
            'reason' => $requestedTaskId === '' ? 'empty_task_id' : 'missing_task',
        ]);
        $pageErrorMessage = '未找到要查看的任务，任务可能已被删除。';
    } else {
        $pdo = getDatabaseConnection();
        $viewTaskSubtasks = loadSubtasksForTask($pdo, $requestedTaskId);
        $viewTaskComments = loadCommentsForTask($pdo, $requestedTaskId);
        $viewTaskHistory = loadTaskHistory($pdo, $requestedTaskId, 20);
        $viewTaskAttachments = loadAttachmentsForTask($pdo, $requestedTaskId);
        $viewTaskRecurrences = loadTaskRecurrences($requestedTaskId);

        writeDebugLog('task_detail_view', [
            'task_id' => $requestedTaskId,
        ], 'success', [
            'title_length' => stringLength((string) $viewTask['title']),
            'content_length' => stringLength((string) $viewTask['content']),
            'status' => normalizeTaskStatus((string) $viewTask['status']),
            'priority' => normalizeTaskPriority((string) ($viewTask['priority'] ?? DEFAULT_TASK_PRIORITY)),
            'category_id' => isset($viewTask['category_id']) && is_string($viewTask['category_id']) ? trim($viewTask['category_id']) : '',
            'due_at' => normalizeStoredDueAt($viewTask['due_at'] ?? ''),
            'updated_at' => (string) $viewTask['updated_at'],
            'subtask_count' => count($viewTaskSubtasks),
            'comment_count' => count($viewTaskComments),
            'history_count' => count($viewTaskHistory),
            'attachment_count' => count($viewTaskAttachments),
            'recurrence_count' => count($viewTaskRecurrences),
        ]);
    }
}

if ($requestMethod === 'POST') {
    $postedTitle = isset($_POST['title']) && is_string($_POST['title']) ? $_POST['title'] : '';
    $postedContent = isset($_POST['content']) && is_string($_POST['content']) ? $_POST['content'] : '';
    $postedStatus = isset($_POST['status']) && is_string($_POST['status']) ? $_POST['status'] : '';
    $postedPriorityRaw = isset($_POST['priority']) && is_string($_POST['priority']) ? trim($_POST['priority']) : '';
    $postedPriority = $postedPriorityRaw === '' ? $effectivePriority : $postedPriorityRaw;
    $postedCategoryId = isset($_POST['category_id']) && is_string($_POST['category_id']) ? trim($_POST['category_id']) : '';
    $postedDueAt = isset($_POST['due_at']) && is_string($_POST['due_at']) ? trim($_POST['due_at']) : '';
    $postedRemindAt = isset($_POST['remind_at']) && is_string($_POST['remind_at']) ? trim($_POST['remind_at']) : '';
    $postedRepeatRuleType = isset($_POST['repeat_rule_type']) && is_string($_POST['repeat_rule_type']) ? trim($_POST['repeat_rule_type']) : '';
    $postedRepeatRuleInterval = isset($_POST['repeat_rule_interval']) && is_string($_POST['repeat_rule_interval']) ? trim($_POST['repeat_rule_interval']) : '1';
    $postedRepeatRuleEndDate = isset($_POST['repeat_rule_end_date']) && is_string($_POST['repeat_rule_end_date']) ? trim($_POST['repeat_rule_end_date']) : '';
    $postedRepeatRule = '';
    if ($postedRepeatRuleType !== '') {
        $postedRepeatRule = buildSubmittedRepeatRule($postedRepeatRuleType, $postedRepeatRuleInterval, $postedRepeatRuleEndDate);
    }
    $postedAction = isset($_POST['form_action']) && is_string($_POST['form_action']) ? trim($_POST['form_action']) : 'create';
    $postedTaskId = isset($_POST['task_id']) && is_string($_POST['task_id']) ? trim($_POST['task_id']) : '';
    $postedPermanentDeleteConfirmation = isset($_POST['permanent_delete_confirmation']) && is_string($_POST['permanent_delete_confirmation']) ? trim($_POST['permanent_delete_confirmation']) : '';
    $postedCategoryName = isset($_POST['category_name']) && is_string($_POST['category_name']) ? $_POST['category_name'] : '';
    $postedManageCategoryId = isset($_POST['manage_category_id']) && is_string($_POST['manage_category_id']) ? trim($_POST['manage_category_id']) : '';
    $postedTagIds = [];
    if (isset($_POST['tag_ids']) && is_array($_POST['tag_ids'])) {
        foreach ($_POST['tag_ids'] as $tagId) {
            if (is_string($tagId) && trim($tagId) !== '') {
                $postedTagIds[] = trim($tagId);
            }
        }
    }

    $formValues = [
        'title' => $postedTitle,
        'content' => $postedContent,
        'status' => isAllowedTaskStatus($postedStatus) ? $postedStatus : '未开始',
        'priority' => isAllowedTaskPriority($postedPriority) ? $postedPriority : $effectivePriority,
        'category_id' => $postedCategoryId,
        'due_at' => $postedDueAt,
        'remind_at' => $postedRemindAt,
        'repeat_rule' => $postedRepeatRule,
        'repeat_rule_type' => $postedRepeatRuleType,
        'repeat_rule_interval' => $postedRepeatRuleInterval,
        'repeat_rule_end_date' => $postedRepeatRuleEndDate,
        'tag_ids' => $postedTagIds,
    ];

    if ($postedAction === 'settings_save') {
        writeDebugLog('settings_save_submit', [
            'submitted_sort_field' => isset($_POST['default_sort_field']) ? $_POST['default_sort_field'] : '',
            'submitted_sort_order' => isset($_POST['default_sort_order']) ? $_POST['default_sort_order'] : '',
            'submitted_page_size' => isset($_POST['default_page_size']) ? $_POST['default_page_size'] : '',
            'submitted_priority' => isset($_POST['default_priority']) ? $_POST['default_priority'] : '',
            'submitted_reminder_lead_time' => isset($_POST['reminder_lead_time']) ? $_POST['reminder_lead_time'] : '',
        ], 'started', [
            'request_method' => 'POST',
        ]);

        $settingsInput = [
            'default_sort_field' => isset($_POST['default_sort_field']) ? $_POST['default_sort_field'] : '',
            'default_sort_order' => isset($_POST['default_sort_order']) ? $_POST['default_sort_order'] : '',
            'default_page_size' => isset($_POST['default_page_size']) ? $_POST['default_page_size'] : '',
            'default_priority' => isset($_POST['default_priority']) ? $_POST['default_priority'] : '',
            'reminder_lead_time' => isset($_POST['reminder_lead_time']) ? $_POST['reminder_lead_time'] : '',
        ];

        $validationResult = validateUserSettingsInput($settingsInput);

        if ($validationResult['errors'] !== []) {
            writeDebugLog('settings_save_validation_failed', [
                'submitted_input' => $settingsInput,
            ], 'failed', [
                'errors' => $validationResult['errors'],
                'sanitized' => $validationResult['sanitized'],
            ]);
            $settingsErrorMessage = implode(' ', $validationResult['errors']);
            $settingsFormValues = $validationResult['sanitized'];
        } else {
            $pdo = getDatabaseConnection();
            $saveResult = saveUserSettings($pdo, $validationResult['sanitized']);
            if ($saveResult) {
                writeDebugLog('settings_save_completed', [
                    'saved_settings' => $validationResult['sanitized'],
                ], 'success', [
                    'redirect' => 'index.php?settings_saved=1',
                ]);
                header('Location: index.php?settings_saved=1', true, 303);
                exit;
            }
            writeDebugLog('settings_save_failed', [
                'saved_settings' => $validationResult['sanitized'],
            ], 'failed', [
                'reason' => 'database_save_failed',
            ]);
            $settingsErrorMessage = '设置保存失败，请稍后重试。';
            $settingsFormValues = $validationResult['sanitized'];
        }
    } elseif ($postedAction === 'category_create') {
        writeDebugLog('category_create_submit', [
            'name_length' => stringLength(normalizeCategoryName($postedCategoryName)),
        ], 'started', [
            'request_method' => 'POST',
        ]);

        $categoryFormValues = [
            'id' => '',
            'name' => $postedCategoryName,
        ];
        $categoryValidation = validateCategoryInput($postedCategoryName);

        if (!$categoryValidation['valid']) {
            $categoryFormErrors = $categoryValidation['errors'];
            writeDebugLog('category_create_validation_failed', [
                'name_length' => stringLength((string) $categoryValidation['name']),
            ], 'failed', [
                'errors' => $categoryFormErrors,
                'database_write_blocked' => true,
            ]);
        } else {
            $categoryResult = saveNewCategory((string) $categoryValidation['name']);
            if ($categoryResult === 'success') {
                header('Location: index.php?category_created=1', true, 303);
                exit;
            }
            $categoryErrorMessage = '分类创建失败，请稍后重试。';
        }
    } elseif ($postedAction === 'category_edit') {
        writeDebugLog('category_update_submit', [
            'category_id' => $postedManageCategoryId,
            'name_length' => stringLength(normalizeCategoryName($postedCategoryName)),
        ], 'started', [
            'request_method' => 'POST',
        ]);

        $categoryFormValues = [
            'id' => $postedManageCategoryId,
            'name' => $postedCategoryName,
        ];
        $categoryValidation = validateCategoryInput($postedCategoryName, $postedManageCategoryId);

        if (!$categoryValidation['valid']) {
            $categoryFormErrors = $categoryValidation['errors'];
            writeDebugLog('category_update_validation_failed', [
                'category_id' => $postedManageCategoryId,
                'name_length' => stringLength((string) $categoryValidation['name']),
            ], 'failed', [
                'errors' => $categoryFormErrors,
                'database_write_blocked' => true,
            ]);
        } else {
            $categoryResult = saveUpdatedCategory($postedManageCategoryId, (string) $categoryValidation['name']);
            if ($categoryResult === 'success') {
                header('Location: index.php?category_edited=1', true, 303);
                exit;
            }
            if ($categoryResult === 'not_found') {
                header('Location: index.php?category_missing=1', true, 303);
                exit;
            }
            $categoryErrorMessage = '分类更新失败，请稍后重试。';
        }
    } elseif ($postedAction === 'category_delete') {
        writeDebugLog('category_delete_submit', [
            'category_id' => $postedManageCategoryId,
        ], 'started', [
            'request_method' => 'POST',
        ]);

        $categoryResult = deleteCategoryById($postedManageCategoryId);
        if ($categoryResult === 'success') {
            header('Location: index.php?category_deleted=1', true, 303);
            exit;
        }
        if ($categoryResult === 'in_use') {
            header('Location: index.php?category_in_use=1', true, 303);
            exit;
        }
        if ($categoryResult === 'not_found') {
            header('Location: index.php?category_missing=1', true, 303);
            exit;
        }
        $categoryErrorMessage = '分类删除失败，请稍后重试。';
    } elseif ($postedAction === 'tag_create') {
        $postedTagName = isset($_POST['tag_name']) && is_string($_POST['tag_name']) ? $_POST['tag_name'] : '';
        $postedTagColor = isset($_POST['tag_color']) && is_string($_POST['tag_color']) ? $_POST['tag_color'] : '#2563eb';

        writeDebugLog('tag_create_submit', [
            'name_length' => stringLength(normalizeTagName($postedTagName)),
            'color' => $postedTagColor,
        ], 'started', [
            'request_method' => 'POST',
        ]);

        $tagFormValues = [
            'id' => '',
            'name' => $postedTagName,
            'color' => $postedTagColor,
        ];
        $tagValidation = validateTagInput($postedTagName);

        if (!$tagValidation['valid']) {
            $tagFormErrors = $tagValidation['errors'];
            writeDebugLog('tag_create_validation_failed', [
                'name_length' => stringLength((string) $tagValidation['name']),
                'color' => $postedTagColor,
            ], 'failed', [
                'errors' => $tagFormErrors,
                'database_write_blocked' => true,
            ]);
        } else {
            $tagResult = saveNewTag((string) $tagValidation['name'], $postedTagColor);
            if ($tagResult === 'success') {
                header('Location: index.php?tag_created=1', true, 303);
                exit;
            }
            $tagErrorMessage = '标签创建失败，请稍后重试。';
        }
    } elseif ($postedAction === 'tag_edit') {
        $postedTagId = isset($_POST['manage_tag_id']) && is_string($_POST['manage_tag_id']) ? trim($_POST['manage_tag_id']) : '';
        $postedTagName = isset($_POST['tag_name']) && is_string($_POST['tag_name']) ? $_POST['tag_name'] : '';
        $postedTagColor = isset($_POST['tag_color']) && is_string($_POST['tag_color']) ? $_POST['tag_color'] : '#2563eb';

        writeDebugLog('tag_update_submit', [
            'tag_id' => $postedTagId,
            'name_length' => stringLength(normalizeTagName($postedTagName)),
            'color' => $postedTagColor,
        ], 'started', [
            'request_method' => 'POST',
        ]);

        $tagFormValues = [
            'id' => $postedTagId,
            'name' => $postedTagName,
            'color' => $postedTagColor,
        ];
        $tagValidation = validateTagInput($postedTagName, $postedTagId);

        if (!$tagValidation['valid']) {
            $tagFormErrors = $tagValidation['errors'];
            writeDebugLog('tag_update_validation_failed', [
                'tag_id' => $postedTagId,
                'name_length' => stringLength((string) $tagValidation['name']),
                'color' => $postedTagColor,
            ], 'failed', [
                'errors' => $tagFormErrors,
                'database_write_blocked' => true,
            ]);
        } else {
            $tagResult = saveUpdatedTag($postedTagId, (string) $tagValidation['name'], $postedTagColor);
            if ($tagResult === 'success') {
                header('Location: index.php?tag_edited=1', true, 303);
                exit;
            }
            if ($tagResult === 'not_found') {
                header('Location: index.php?tag_missing=1', true, 303);
                exit;
            }
            $tagErrorMessage = '标签更新失败，请稍后重试。';
        }
    } elseif ($postedAction === 'tag_delete') {
        $postedTagId = isset($_POST['manage_tag_id']) && is_string($_POST['manage_tag_id']) ? trim($_POST['manage_tag_id']) : '';

        writeDebugLog('tag_delete_submit', [
            'tag_id' => $postedTagId,
        ], 'started', [
            'request_method' => 'POST',
        ]);

        $tagResult = deleteTagById($postedTagId);
        if ($tagResult === 'success') {
            header('Location: index.php?tag_deleted=1', true, 303);
            exit;
        }
        if ($tagResult === 'not_found') {
            header('Location: index.php?tag_missing=1', true, 303);
            exit;
        }
        $tagErrorMessage = '标签删除失败，请稍后重试。';
    } elseif ($postedAction === 'subtask_create') {
        $postedSubtaskTitle = isset($_POST['subtask_title']) && is_string($_POST['subtask_title']) ? $_POST['subtask_title'] : '';
        $postedSubtaskTaskId = isset($_POST['subtask_task_id']) && is_string($_POST['subtask_task_id']) ? trim($_POST['subtask_task_id']) : '';

        writeDebugLog('subtask_create_submit', [
            'task_id' => $postedSubtaskTaskId,
            'title_length' => stringLength(trim($postedSubtaskTitle)),
        ], 'started', [
            'request_method' => 'POST',
        ]);

        $subtaskValidation = validateSubtaskInput($postedSubtaskTitle, $postedSubtaskTaskId, $tasks);

        if (!$subtaskValidation['valid']) {
            writeDebugLog('subtask_create_validation_failed', [
                'task_id' => $postedSubtaskTaskId,
                'title_length' => stringLength(trim($postedSubtaskTitle)),
            ], 'failed', [
                'errors' => $subtaskValidation['errors'],
                'database_write_blocked' => true,
            ]);
            $subtaskErrorMessage = implode(' ', $subtaskValidation['errors']);
        } else {
            $createResult = saveNewSubtask($postedSubtaskTaskId, $subtaskValidation['title']);
            if ($createResult === 'success') {
                header('Location: index.php?view_task=' . urlencode($postedSubtaskTaskId) . '&subtask_created=1#subtasks', true, 303);
                exit;
            }
            $subtaskErrorMessage = '子任务创建失败，请稍后重试。';
        }
    } elseif ($postedAction === 'subtask_edit') {
        $postedSubtaskId = isset($_POST['subtask_id']) && is_string($_POST['subtask_id']) ? trim($_POST['subtask_id']) : '';
        $postedSubtaskTitle = isset($_POST['subtask_title']) && is_string($_POST['subtask_title']) ? $_POST['subtask_title'] : '';
        $postedSubtaskTaskId = isset($_POST['subtask_task_id']) && is_string($_POST['subtask_task_id']) ? trim($_POST['subtask_task_id']) : '';

        writeDebugLog('subtask_update_submit', [
            'subtask_id' => $postedSubtaskId,
            'task_id' => $postedSubtaskTaskId,
            'title_length' => stringLength(trim($postedSubtaskTitle)),
        ], 'started', [
            'request_method' => 'POST',
        ]);

        if ($postedSubtaskId === '') {
            writeDebugLog('subtask_update_not_found', [
                'subtask_id' => $postedSubtaskId,
            ], 'failed', [
                'stage' => 'submit',
                'reason' => 'empty_subtask_id',
            ]);
            header('Location: index.php?view_task=' . urlencode($postedSubtaskTaskId) . '&subtask_missing=1#subtasks', true, 303);
            exit;
        }

        $normalizedTitle = trim($postedSubtaskTitle);
        if ($normalizedTitle === '') {
            writeDebugLog('subtask_update_validation_failed', [
                'subtask_id' => $postedSubtaskId,
                'title_length' => 0,
            ], 'failed', [
                'reason' => 'empty_title',
                'database_write_blocked' => true,
            ]);
            $subtaskErrorMessage = '子任务标题不能为空。';
        } elseif (stringLength($normalizedTitle) > MAX_SUBTASK_TITLE_LENGTH) {
            writeDebugLog('subtask_update_validation_failed', [
                'subtask_id' => $postedSubtaskId,
                'title_length' => stringLength($normalizedTitle),
            ], 'failed', [
                'reason' => 'title_too_long',
                'max_length' => MAX_SUBTASK_TITLE_LENGTH,
                'database_write_blocked' => true,
            ]);
            $subtaskErrorMessage = '子任务标题不能超过 ' . MAX_SUBTASK_TITLE_LENGTH . ' 个字符。';
        } else {
            $updateResult = saveUpdatedSubtask($postedSubtaskId, $normalizedTitle);
            if ($updateResult === 'success') {
                header('Location: index.php?view_task=' . urlencode($postedSubtaskTaskId) . '&subtask_edited=1#subtasks', true, 303);
                exit;
            }
            if ($updateResult === 'not_found') {
                header('Location: index.php?view_task=' . urlencode($postedSubtaskTaskId) . '&subtask_missing=1#subtasks', true, 303);
                exit;
            }
            $subtaskErrorMessage = '子任务更新失败，请稍后重试。';
        }
    } elseif ($postedAction === 'subtask_delete') {
        $postedSubtaskId = isset($_POST['subtask_id']) && is_string($_POST['subtask_id']) ? trim($_POST['subtask_id']) : '';
        $postedSubtaskTaskId = isset($_POST['subtask_task_id']) && is_string($_POST['subtask_task_id']) ? trim($_POST['subtask_task_id']) : '';

        writeDebugLog('subtask_delete_submit', [
            'subtask_id' => $postedSubtaskId,
            'task_id' => $postedSubtaskTaskId,
        ], 'started', [
            'request_method' => 'POST',
        ]);

        if ($postedSubtaskId === '') {
            writeDebugLog('subtask_delete_not_found', [
                'subtask_id' => $postedSubtaskId,
            ], 'failed', [
                'stage' => 'submit',
                'reason' => 'empty_subtask_id',
            ]);
            header('Location: index.php?view_task=' . urlencode($postedSubtaskTaskId) . '&subtask_missing=1#subtasks', true, 303);
            exit;
        }

        $deleteResult = deleteSubtaskById($postedSubtaskId);
        if ($deleteResult === 'success') {
            header('Location: index.php?view_task=' . urlencode($postedSubtaskTaskId) . '&subtask_deleted=1#subtasks', true, 303);
            exit;
        }
        if ($deleteResult === 'not_found') {
            header('Location: index.php?view_task=' . urlencode($postedSubtaskTaskId) . '&subtask_missing=1#subtasks', true, 303);
            exit;
        }
        $subtaskErrorMessage = '子任务删除失败，请稍后重试。';
    } elseif ($postedAction === 'subtask_toggle') {
        $postedSubtaskId = isset($_POST['subtask_id']) && is_string($_POST['subtask_id']) ? trim($_POST['subtask_id']) : '';
        $postedSubtaskTaskId = isset($_POST['subtask_task_id']) && is_string($_POST['subtask_task_id']) ? trim($_POST['subtask_task_id']) : '';

        writeDebugLog('subtask_toggle_submit', [
            'subtask_id' => $postedSubtaskId,
            'task_id' => $postedSubtaskTaskId,
        ], 'started', [
            'request_method' => 'POST',
        ]);

        if ($postedSubtaskId === '') {
            writeDebugLog('subtask_toggle_not_found', [
                'subtask_id' => $postedSubtaskId,
            ], 'failed', [
                'stage' => 'submit',
                'reason' => 'empty_subtask_id',
            ]);
            header('Location: index.php?view_task=' . urlencode($postedSubtaskTaskId) . '&subtask_missing=1#subtasks', true, 303);
            exit;
        }

        $toggleResult = toggleSubtaskCompletion($postedSubtaskId);
        if ($toggleResult === 'success') {
            header('Location: index.php?view_task=' . urlencode($postedSubtaskTaskId) . '&subtask_toggled=1#subtasks', true, 303);
            exit;
        }
        if ($toggleResult === 'not_found') {
            header('Location: index.php?view_task=' . urlencode($postedSubtaskTaskId) . '&subtask_missing=1#subtasks', true, 303);
            exit;
        }
        $subtaskErrorMessage = '子任务状态更新失败，请稍后重试。';
    } elseif ($postedAction === 'comment_create') {
        $postedCommentTaskId = isset($_POST['comment_task_id']) && is_string($_POST['comment_task_id']) ? trim($_POST['comment_task_id']) : '';
        $postedCommentContent = isset($_POST['comment_content']) && is_string($_POST['comment_content']) ? $_POST['comment_content'] : '';

        writeDebugLog('comment_create_submit', [
            'task_id' => $postedCommentTaskId,
            'content_length' => stringLength(trim($postedCommentContent)),
        ], 'started', [
            'request_method' => 'POST',
        ]);

        $commentValidation = validateCommentInput($postedCommentContent, $postedCommentTaskId, $tasks);

        if (!$commentValidation['valid']) {
            writeDebugLog('comment_create_validation_failed', [
                'task_id' => $postedCommentTaskId,
                'content_length' => stringLength(trim($postedCommentContent)),
            ], 'failed', [
                'errors' => $commentValidation['errors'],
                'database_write_blocked' => true,
            ]);
            $commentErrorMessage = implode(' ', $commentValidation['errors']);
        } else {
            $createResult = saveComment($postedCommentTaskId, $commentValidation['content']);
            if ($createResult === 'success') {
                writeDebugLog('comment_create_completed', [
                    'task_id' => $postedCommentTaskId,
                    'content_length' => stringLength($commentValidation['content']),
                ], 'success', [
                    'redirect' => 'index.php?view_task=' . urlencode($postedCommentTaskId) . '&comment_added=1#comments',
                ]);
                header('Location: index.php?view_task=' . urlencode($postedCommentTaskId) . '&comment_added=1#comments', true, 303);
                exit;
            }
            $commentErrorMessage = '评论添加失败，请稍后重试。';
        }
    } elseif ($postedAction === 'attachment_delete') {
        $postedAttachmentId = isset($_POST['attachment_id']) && is_string($_POST['attachment_id']) ? trim($_POST['attachment_id']) : '';
        $postedAttachmentTaskId = isset($_POST['attachment_task_id']) && is_string($_POST['attachment_task_id']) ? trim($_POST['attachment_task_id']) : '';

        writeDebugLog('attachment_delete_submit', [
            'attachment_id' => $postedAttachmentId,
            'task_id' => $postedAttachmentTaskId,
        ], 'started', [
            'request_method' => 'POST',
        ]);

        if ($postedAttachmentId === '') {
            writeDebugLog('attachment_delete_validation_failed', [
                'task_id' => $postedAttachmentTaskId,
            ], 'failed', [
                'reason' => 'empty_attachment_id',
            ]);
            header('Location: index.php?view_task=' . urlencode($postedAttachmentTaskId) . '&attachment_missing=1#attachments', true, 303);
            exit;
        }

        if ($postedAttachmentTaskId === '') {
            writeDebugLog('attachment_delete_validation_failed', [
                'attachment_id' => $postedAttachmentId,
            ], 'failed', [
                'reason' => 'empty_task_id',
            ]);
            header('Location: index.php?attachment_missing=1#attachments', true, 303);
            exit;
        }

        $deleteResult = deleteAttachmentById($postedAttachmentId, $postedAttachmentTaskId);
        if ($deleteResult === 'success') {
            writeDebugLog('attachment_delete_completed', [
                'attachment_id' => $postedAttachmentId,
                'task_id' => $postedAttachmentTaskId,
            ], 'success', [
                'redirect' => 'index.php?view_task=' . urlencode($postedAttachmentTaskId) . '&attachment_deleted=1#attachments',
            ]);
            header('Location: index.php?view_task=' . urlencode($postedAttachmentTaskId) . '&attachment_deleted=1#attachments', true, 303);
            exit;
        }

        if ($deleteResult === 'not_found') {
            header('Location: index.php?view_task=' . urlencode($postedAttachmentTaskId) . '&attachment_missing=1#attachments', true, 303);
            exit;
        }

        $attachmentErrorMessage = '附件删除失败，请稍后重试。';
    } elseif ($postedAction === 'attachment_add') {
        $postedAttachmentTaskId = isset($_POST['attachment_task_id']) && is_string($_POST['attachment_task_id']) ? trim($_POST['attachment_task_id']) : '';

        writeDebugLog('attachment_add_submit', [
            'task_id' => $postedAttachmentTaskId,
        ], 'started', [
            'request_method' => 'POST',
        ]);

        if ($postedAttachmentTaskId === '') {
            writeDebugLog('attachment_add_validation_failed', [
                'reason' => 'empty_task_id',
            ], 'failed', [
                'database_write_blocked' => true,
            ]);
            header('Location: index.php?attachment_error=empty_task#attachments', true, 303);
            exit;
        }

        $attachmentFileName = '';
        $attachmentFileSize = 0;
        $attachmentMimeType = '';
        $attachmentStoragePath = '';

        if (isset($_FILES['attachment_file']) && is_array($_FILES['attachment_file'])) {
            $uploadedFile = $_FILES['attachment_file'];
            if ($uploadedFile['error'] === UPLOAD_ERR_OK && $uploadedFile['size'] > 0) {
                $attachmentFileName = $uploadedFile['name'];
                $attachmentFileSize = (int) $uploadedFile['size'];
                $attachmentMimeType = isset($uploadedFile['type']) && is_string($uploadedFile['type']) ? $uploadedFile['type'] : '';

                $validationResult = validateAttachmentInput($attachmentFileName, $attachmentFileSize, $attachmentMimeType, $postedAttachmentTaskId);

                if (!$validationResult['valid']) {
                    writeDebugLog('attachment_add_validation_failed', [
                        'task_id' => $postedAttachmentTaskId,
                        'file_name' => $attachmentFileName,
                        'file_size' => $attachmentFileSize,
                        'mime_type' => $attachmentMimeType,
                    ], 'failed', [
                        'errors' => $validationResult['errors'],
                        'database_write_blocked' => true,
                    ]);
                    header('Location: index.php?view_task=' . urlencode($postedAttachmentTaskId) . '&attachment_error=validation_failed#attachments', true, 303);
                    exit;
                }

                $storageDir = __DIR__ . '/data/attachments';
                if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
                    writeDebugLog('attachment_add_storage_error', [
                        'task_id' => $postedAttachmentTaskId,
                        'file_name' => $attachmentFileName,
                    ], 'failed', [
                        'reason' => 'cannot_create_storage_directory',
                    ]);
                    header('Location: index.php?view_task=' . urlencode($postedAttachmentTaskId) . '&attachment_error=storage_error#attachments', true, 303);
                    exit;
                }

                $attachmentId = createAttachmentId();
                $fileExtension = pathinfo($attachmentFileName, PATHINFO_EXTENSION);
                $newFileName = $attachmentId . ($fileExtension !== '' ? '.' . $fileExtension : '');
                $attachmentStoragePath = 'data/attachments/' . $newFileName;
                $fullStoragePath = __DIR__ . '/' . $attachmentStoragePath;

                if (!move_uploaded_file($uploadedFile['tmp_name'], $fullStoragePath)) {
                    writeDebugLog('attachment_add_file_save_error', [
                        'task_id' => $postedAttachmentTaskId,
                        'file_name' => $attachmentFileName,
                        'storage_path' => $attachmentStoragePath,
                    ], 'failed', [
                        'reason' => 'file_move_failed',
                    ]);
                    header('Location: index.php?view_task=' . urlencode($postedAttachmentTaskId) . '&attachment_error=file_save_failed#attachments', true, 303);
                    exit;
                }

                $saveResult = saveNewAttachment(
                    $postedAttachmentTaskId,
                    $validationResult['file_name'],
                    $attachmentFileSize,
                    $validationResult['mime_type'],
                    $attachmentStoragePath
                );

                if ($saveResult === 'success') {
                    writeDebugLog('attachment_add_completed', [
                        'attachment_id' => $attachmentId,
                        'task_id' => $postedAttachmentTaskId,
                        'file_name' => $validationResult['file_name'],
                        'file_size' => $attachmentFileSize,
                        'mime_type' => $validationResult['mime_type'],
                    ], 'success', [
                        'redirect' => 'index.php?view_task=' . urlencode($postedAttachmentTaskId) . '&attachment_added=1#attachments',
                    ]);
                    header('Location: index.php?view_task=' . urlencode($postedAttachmentTaskId) . '&attachment_added=1#attachments', true, 303);
                    exit;
                }

                if (file_exists($fullStoragePath)) {
                    unlink($fullStoragePath);
                }

                $attachmentErrorMessage = '附件保存失败，请稍后重试。';
            } elseif ($uploadedFile['error'] !== UPLOAD_ERR_NO_FILE) {
                $uploadErrors = [
                    UPLOAD_ERR_INI_SIZE => '文件大小超过服务器限制。',
                    UPLOAD_ERR_FORM_SIZE => '文件大小超过表单限制。',
                    UPLOAD_ERR_PARTIAL => '文件只上传了一部分。',
                    UPLOAD_ERR_NO_TMP_DIR => '找不到临时文件夹。',
                    UPLOAD_ERR_CANT_WRITE => '文件写入失败。',
                    UPLOAD_ERR_EXTENSION => '文件上传被扩展阻止。',
                ];
                $errorMessage = isset($uploadErrors[$uploadedFile['error']]) ? $uploadErrors[$uploadedFile['error']] : '未知上传错误。';
                writeDebugLog('attachment_add_upload_error', [
                    'task_id' => $postedAttachmentTaskId,
                    'upload_error_code' => $uploadedFile['error'],
                ], 'failed', [
                    'reason' => 'upload_error',
                    'message' => $errorMessage,
                    'database_write_blocked' => true,
                ]);
                header('Location: index.php?view_task=' . urlencode($postedAttachmentTaskId) . '&attachment_error=upload_failed#attachments', true, 303);
                exit;
            }
        }

        if ($attachmentFileName === '') {
            writeDebugLog('attachment_add_validation_failed', [
                'task_id' => $postedAttachmentTaskId,
            ], 'failed', [
                'reason' => 'no_file_selected',
                'database_write_blocked' => true,
            ]);
            header('Location: index.php?view_task=' . urlencode($postedAttachmentTaskId) . '&attachment_error=no_file#attachments', true, 303);
            exit;
        }
    } elseif ($postedAction === 'csv_import') {
        writeDebugLog('csv_import_submit', [], 'started', [
            'request_method' => 'POST',
        ]);

        $importResult = handleCsvImport();

        if ($importResult['success']) {
            $message = 'CSV 导入完成，成功 ' . $importResult['success_count'] . ' 条';
            if ($importResult['fail_count'] > 0) {
                $message .= '，失败 ' . $importResult['fail_count'] . ' 条';
            }
            $failuresEncoded = [];
            foreach ($importResult['failures'] as $failure) {
                $failuresEncoded[] = urlencode($failure);
            }
            $failuresParam = implode(',', $failuresEncoded);
            writeDebugLog('csv_import_submit_success', [
                'success_count' => $importResult['success_count'],
                'fail_count' => $importResult['fail_count'],
                'created_tags' => $importResult['created_tags'],
                'created_categories' => $importResult['created_categories'],
            ], 'success');
            header('Location: index.php?csv_import=1&success_count=' . (int) $importResult['success_count'] . '&fail_count=' . (int) $importResult['fail_count'] . '&failures=' . $failuresParam, true, 303);
            exit;
        } else {
            $importErrorMessage = $importResult['message'];
            writeDebugLog('csv_import_submit_failed', [
                'message' => $importErrorMessage,
            ], 'failed');
        }
    } elseif ($postedAction === 'csv_export') {
        writeDebugLog('csv_export_submit', [], 'started', [
            'request_method' => 'POST',
        ]);

        $exportKeyword = isset($_POST['export_keyword']) && is_string($_POST['export_keyword']) ? trim($_POST['export_keyword']) : '';
        $exportStatus = isset($_POST['export_status']) && is_string($_POST['export_status']) ? trim($_POST['export_status']) : '';
        $exportPriority = isset($_POST['export_priority']) && is_string($_POST['export_priority']) ? trim($_POST['export_priority']) : '';
        $exportTagId = isset($_POST['export_tag_id']) && is_string($_POST['export_tag_id']) ? trim($_POST['export_tag_id']) : '';
        $exportVisibility = isset($_POST['export_visibility']) && is_string($_POST['export_visibility']) ? trim($_POST['export_visibility']) : TASK_VISIBILITY_ACTIVE;

        if ($exportKeyword !== '' && stringLength($exportKeyword) > MAX_SEARCH_KEYWORD_LENGTH) {
            $exportKeyword = mb_substr($exportKeyword, 0, MAX_SEARCH_KEYWORD_LENGTH);
        }
        if ($exportStatus !== '' && !isAllowedTaskStatus($exportStatus)) {
            $exportStatus = '';
        }
        if ($exportPriority !== '' && !isAllowedTaskPriority($exportPriority)) {
            $exportPriority = '';
        }
        if ($exportTagId !== '' && (stringLength($exportTagId) > 36 || preg_match('/[^a-zA-Z0-9\-]/', $exportTagId) === 1)) {
            $exportTagId = '';
        }
        if ($exportVisibility !== TASK_VISIBILITY_ACTIVE && $exportVisibility !== TASK_VISIBILITY_ARCHIVED && $exportVisibility !== TASK_VISIBILITY_TRASH) {
            $exportVisibility = TASK_VISIBILITY_ACTIVE;
        }

        writeDebugLog('csv_export_parameters', [
            'keyword_length' => stringLength($exportKeyword),
            'status' => $exportStatus,
            'priority' => $exportPriority,
            'tag_id' => $exportTagId,
            'visibility' => $exportVisibility,
        ], 'validated');

        handleCsvExport($exportKeyword, $exportStatus, $exportPriority, $exportTagId, $exportVisibility);
    } elseif ($postedAction === 'database_backup') {
        writeDebugLog('database_backup_submit', [], 'started', [
            'request_method' => 'POST',
        ]);

        handleDatabaseBackup();
    } elseif ($postedAction === 'database_restore') {
        writeDebugLog('database_restore_submit', [], 'started', [
            'request_method' => 'POST',
        ]);

        $restoreResult = handleDatabaseRestore();
        if ($restoreResult['success']) {
            writeDebugLog('database_restore_completed', [
                'task_count' => $restoreResult['task_count'] ?? 0,
                'version' => $restoreResult['version'] ?? 'unknown',
            ], 'success');
            header('Location: index.php?restore_success=1&task_count=' . (int) ($restoreResult['task_count'] ?? 0), true, 303);
            exit;
        } else {
            writeDebugLog('database_restore_completed_with_error', [
                'error' => $restoreResult['error'] ?? 'unknown_error',
            ], 'failed');
            $restoreErrorMessage = $restoreResult['error'] ?? '数据库恢复失败。';
        }
    } elseif ($postedAction === 'bulk_action') {
        $postedBulkAction = isset($_POST['bulk_action']) && is_string($_POST['bulk_action']) ? trim($_POST['bulk_action']) : '';
        $postedBulkTaskIds = normalizeSubmittedBulkTaskIds($_POST['bulk_task_ids'] ?? []);
        $postedBulkCategoryId = isset($_POST['bulk_category_id']) && is_string($_POST['bulk_category_id']) ? trim($_POST['bulk_category_id']) : '';
        $postedBulkPriority = isset($_POST['bulk_priority']) && is_string($_POST['bulk_priority']) ? trim($_POST['bulk_priority']) : DEFAULT_TASK_PRIORITY;

        if ($postedBulkTaskIds === []) {
            writeDebugLog('task_bulk_operation_submit', [
                'bulk_action' => $postedBulkAction,
                'selected_count' => 0,
            ], 'failed', [
                'reason' => 'empty_selection',
                'database_write_blocked' => true,
            ]);
            header('Location: index.php?bulk_result=empty&bulk_action=' . urlencode($postedBulkAction) . '&bulk_success=0&bulk_failed=0', true, 303);
            exit;
        }

        $bulkSummary = executeBulkTaskOperation($postedBulkAction, $postedBulkTaskIds, $postedBulkCategoryId, $postedBulkPriority);
        $bulkReasonPairs = [];
        foreach ($bulkSummary['failure_reasons'] as $reason => $count) {
            $bulkReasonPairs[] = urlencode((string) $reason) . ':' . max(0, (int) $count);
        }
        $bulkRedirectQuery = http_build_query([
            'bulk_result' => $bulkSummary['status'],
            'bulk_action' => $postedBulkAction,
            'bulk_success' => (int) $bulkSummary['success_count'],
            'bulk_failed' => (int) $bulkSummary['failed_count'],
        ]);
        if ($bulkReasonPairs !== []) {
            $bulkRedirectQuery .= '&bulk_reasons=' . implode(',', $bulkReasonPairs);
        }
        header('Location: index.php?' . $bulkRedirectQuery, true, 303);
        exit;
    } elseif ($postedAction === 'archive') {
        if ($postedTaskId === '') {
            writeDebugLog('task_archive_not_found', [
                'task_id' => $postedTaskId,
            ], 'failed', [
                'stage' => 'submit',
                'reason' => 'empty_task_id',
                'database_write_blocked' => true,
            ]);
            header('Location: index.php?archive_missing=1', true, 303);
            exit;
        }

        $archiveResult = archiveTaskById($postedTaskId);
        if ($archiveResult === 'success') {
            writeDebugLog('task_archive_completed', [
                'task_id' => $postedTaskId,
            ], 'success', [
                'redirect' => 'index.php?archived=1',
            ]);
            header('Location: index.php?archived=1', true, 303);
            exit;
        }
        if ($archiveResult === 'already_archived') {
            header('Location: index.php?archive_duplicate=1', true, 303);
            exit;
        }
        if ($archiveResult === 'not_found') {
            header('Location: index.php?archive_missing=1', true, 303);
            exit;
        }
        header('Location: index.php?archive_failed=1', true, 303);
        exit;
    } elseif ($postedAction === 'restore_archive') {
        if ($postedTaskId === '') {
            writeDebugLog('task_archive_restore_not_found', [
                'task_id' => $postedTaskId,
            ], 'failed', [
                'stage' => 'submit',
                'reason' => 'empty_task_id',
                'database_write_blocked' => true,
            ]);
            header('Location: index.php?action=archive&restore_missing=1', true, 303);
            exit;
        }

        $restoreResult = restoreArchivedTaskById($postedTaskId);
        if ($restoreResult === 'success') {
            writeDebugLog('task_archive_restore_completed', [
                'task_id' => $postedTaskId,
            ], 'success', [
                'redirect' => 'index.php?action=archive&restored=1',
            ]);
            header('Location: index.php?action=archive&restored=1', true, 303);
            exit;
        }
        if ($restoreResult === 'not_archived') {
            header('Location: index.php?action=archive&restore_not_archived=1', true, 303);
            exit;
        }
        if ($restoreResult === 'not_found') {
            header('Location: index.php?action=archive&restore_missing=1', true, 303);
            exit;
        }
        header('Location: index.php?action=archive&restore_failed=1', true, 303);
        exit;
    } elseif ($postedAction === 'delete') {
        writeDebugLog('task_delete_submit', [
            'task_id' => $postedTaskId,
        ], 'started', [
            'request_method' => 'POST',
        ]);

        if ($postedTaskId === '') {
            writeDebugLog('task_delete_not_found', [
                'task_id' => $postedTaskId,
            ], 'failed', [
                'stage' => 'submit',
                'reason' => 'empty_task_id',
            ]);
            header('Location: index.php?delete_missing=1', true, 303);
            exit;
        }

        $deleteResult = deleteTaskById($postedTaskId);
        if ($deleteResult === 'success') {
            writeDebugLog('task_delete_completed', [
                'task_id' => $postedTaskId,
            ], 'success', [
                'redirect' => 'index.php?deleted=1',
            ]);
            header('Location: index.php?deleted=1', true, 303);
            exit;
        }

        if ($deleteResult === 'not_found') {
            header('Location: index.php?delete_missing=1', true, 303);
            exit;
        }

        $saveErrorMessage = '任务删除失败，请稍后重试。';
    } elseif ($postedAction === 'restore_trash') {
        if ($postedTaskId === '') {
            writeDebugLog('task_trash_restore_not_found', [
                'task_id' => $postedTaskId,
            ], 'failed', [
                'stage' => 'submit',
                'reason' => 'empty_task_id',
                'operation_type' => 'restore_from_trash',
                'database_write_blocked' => true,
            ]);
            header('Location: index.php?action=trash&trash_restore_missing=1', true, 303);
            exit;
        }

        $restoreTrashResult = restoreDeletedTaskById($postedTaskId);
        if ($restoreTrashResult === 'success') {
            writeDebugLog('task_trash_restore_completed', [
                'task_id' => $postedTaskId,
            ], 'success', [
                'operation_type' => 'restore_from_trash',
                'redirect' => 'index.php?action=trash&trash_restored=1',
            ]);
            header('Location: index.php?action=trash&trash_restored=1', true, 303);
            exit;
        }
        if ($restoreTrashResult === 'not_found') {
            header('Location: index.php?action=trash&trash_restore_missing=1', true, 303);
            exit;
        }
        header('Location: index.php?action=trash&trash_restore_failed=1', true, 303);
        exit;
    } elseif ($postedAction === 'permanent_delete') {
        if ($postedTaskId === '') {
            writeDebugLog('task_trash_permanent_delete_not_found', [
                'task_id' => $postedTaskId,
            ], 'failed', [
                'stage' => 'submit',
                'reason' => 'empty_task_id',
                'operation_type' => 'permanent_delete',
                'database_write_blocked' => true,
            ]);
            header('Location: index.php?action=trash&permanent_delete_missing=1', true, 303);
            exit;
        }

        $permanentDeleteResult = permanentlyDeleteTaskById($postedTaskId, $postedPermanentDeleteConfirmation);
        if ($permanentDeleteResult === 'success') {
            writeDebugLog('task_trash_permanent_delete_completed', [
                'task_id' => $postedTaskId,
            ], 'success', [
                'operation_type' => 'permanent_delete',
                'redirect' => 'index.php?action=trash&permanently_deleted=1',
            ]);
            header('Location: index.php?action=trash&permanently_deleted=1', true, 303);
            exit;
        }
        if ($permanentDeleteResult === 'confirmation_missing') {
            header('Location: index.php?action=trash&permanent_delete_confirm_missing=1', true, 303);
            exit;
        }
        if ($permanentDeleteResult === 'not_found') {
            header('Location: index.php?action=trash&permanent_delete_missing=1', true, 303);
            exit;
        }
        header('Location: index.php?action=trash&permanent_delete_failed=1', true, 303);
        exit;
    } elseif ($postedAction === 'status_change') {
        writeDebugLog('task_status_change_submit', [
            'task_id' => $postedTaskId,
            'submitted_status' => $postedStatus,
        ], 'started', [
            'request_method' => 'POST',
            'allowed_statuses' => ALLOWED_STATUSES,
        ]);

        if ($postedTaskId === '') {
            writeDebugLog('task_status_not_found', [
                'task_id' => $postedTaskId,
                'submitted_status' => $postedStatus,
            ], 'failed', [
                'stage' => 'submit',
                'reason' => 'empty_task_id',
            ]);
            $pageErrorMessage = '未找到要更新状态的任务，任务可能已被删除。';
        } elseif (!isAllowedTaskStatus($postedStatus)) {
            writeDebugLog('task_status_invalid_submit', [
                'task_id' => $postedTaskId,
                'submitted_status' => $postedStatus,
            ], 'failed', [
                'stage' => 'submit',
                'reason' => 'status_outside_allowed_enum',
                'allowed_statuses' => ALLOWED_STATUSES,
            ]);
            header('Location: index.php?status_invalid=1', true, 303);
            exit;
        } else {
            $statusChangeResult = updateTaskStatus($postedTaskId, $postedStatus);
            if ($statusChangeResult === 'success') {
                $statusChangeRedirectUrl = buildStatusChangeRedirectUrl();
                writeDebugLog('task_status_change_completed', [
                    'task_id' => $postedTaskId,
                    'new_status' => $postedStatus,
                ], 'success', [
                    'redirect' => $statusChangeRedirectUrl,
                    'recurrence_generation' => getLastRecurrenceGenerationResult(),
                ]);
                header('Location: ' . $statusChangeRedirectUrl, true, 303);
                exit;
            }

            if ($statusChangeResult === 'invalid_status') {
                header('Location: index.php?status_invalid=1', true, 303);
                exit;
            }

            if ($statusChangeResult === 'not_found') {
                $pageErrorMessage = '未找到要更新状态的任务，任务可能已被删除。';
            } else {
                $saveErrorMessage = '任务状态更新失败，请稍后重试。';
            }
        }
    } elseif ($postedAction === 'edit') {
        $isEditRequest = true;
        $isEditMode = true;
        $editTaskId = $postedTaskId;

        writeDebugLog('task_edit_submit', [
            'task_id' => $editTaskId,
            'title_length' => stringLength(trim($postedTitle)),
            'content_length' => stringLength(trim($postedContent)),
            'status' => $postedStatus,
            'priority' => $postedPriority,
            'category_id' => $postedCategoryId,
            'submitted_due_at' => $postedDueAt,
        ], 'started');

        $existingTask = $editTaskId !== '' ? findTaskById($tasks, $editTaskId) : null;
        if ($existingTask === null) {
            writeDebugLog('task_edit_not_found', [
                'task_id' => $editTaskId,
                'title_length' => stringLength(trim($postedTitle)),
                'content_length' => stringLength(trim($postedContent)),
                'status' => $postedStatus,
                'priority' => $postedPriority,
                'category_id' => $postedCategoryId,
                'submitted_due_at' => $postedDueAt,
            ], 'failed', [
                'stage' => 'submit',
                'reason' => $editTaskId === '' ? 'empty_task_id' : 'missing_task',
            ]);
            $pageErrorMessage = '未找到要保存的任务，任务可能已被删除。';
            $isEditMode = false;
        } else {
            $formErrors = validateTaskInput($postedTitle, $postedContent, $postedStatus, $postedPriority, $postedDueAt, $postedRemindAt, $postedCategoryId, $categories, 'edit', $editTaskId, $postedRepeatRule);

            if ($formErrors !== []) {
                writeDebugLog('task_edit_validation_failed', [
                    'task_id' => $editTaskId,
                    'title_length' => stringLength(trim($postedTitle)),
                    'content_length' => stringLength(trim($postedContent)),
                    'status' => $postedStatus,
                    'priority' => $postedPriority,
                    'category_id' => $postedCategoryId,
                    'submitted_due_at' => $postedDueAt,
                    'submitted_remind_at' => $postedRemindAt,
                ], 'failed', [
                    'errors' => $formErrors,
                ]);
                if (isset($formErrors['priority'])) {
                    writeDebugLog('task_priority_invalid_submit', [
                        'task_id' => $editTaskId,
                        'submitted_priority' => $postedPriority,
                        'form_action' => 'edit',
                    ], 'failed', [
                        'reason' => 'priority_outside_allowed_enum',
                        'allowed_priorities' => ALLOWED_PRIORITIES,
                        'database_write_blocked' => true,
                    ]);
                }
                if (isset($formErrors['due_at'])) {
                    writeDebugLog('task_due_at_invalid_submit', [
                        'task_id' => $editTaskId,
                        'submitted_due_at' => $postedDueAt,
                        'form_action' => 'edit',
                    ], 'failed', [
                        'reason' => 'due_at_validation_failed',
                        'min_due_at' => MIN_DUE_AT,
                        'max_due_at' => MAX_DUE_AT,
                        'database_write_blocked' => true,
                    ]);
                }
                if (isset($formErrors['category_id'])) {
                    writeDebugLog('task_category_invalid_submit', [
                        'task_id' => $editTaskId,
                        'submitted_category_id' => $postedCategoryId,
                        'form_action' => 'edit',
                    ], 'failed', [
                        'reason' => 'category_validation_failed',
                        'database_write_blocked' => true,
                    ]);
                }
                if (isset($formErrors['remind_at'])) {
                    writeDebugLog('task_remind_at_invalid_submit', [
                        'task_id' => $editTaskId,
                        'submitted_remind_at' => $postedRemindAt,
                        'submitted_due_at' => $postedDueAt,
                        'form_action' => 'edit',
                    ], 'failed', [
                        'reason' => 'remind_at_validation_failed',
                        'min_remind_at' => MIN_REMIND_AT,
                        'max_remind_at' => MAX_REMIND_AT,
                        'database_write_blocked' => true,
                    ]);
                }
                if (isset($formErrors['repeat_rule'])) {
                    writeDebugLog('task_repeat_rule_invalid_submit', [
                        'task_id' => $editTaskId,
                        'submitted_repeat_rule_type' => $postedRepeatRuleType,
                        'submitted_repeat_rule_interval' => $postedRepeatRuleInterval,
                        'submitted_repeat_rule_end_date' => $postedRepeatRuleEndDate,
                        'form_action' => 'edit',
                    ], 'failed', [
                        'reason' => 'repeat_rule_validation_failed',
                        'database_write_blocked' => true,
                    ]);
                }
            } else {
                $dueAtValidation = validateDueAtInput($postedDueAt);
                $normalizedDueAt = is_string($dueAtValidation['normalized']) ? $dueAtValidation['normalized'] : '';
                $remindAtValidation = validateRemindAtInput($postedRemindAt, $normalizedDueAt);
                $normalizedRemindAt = is_string($remindAtValidation['normalized']) ? $remindAtValidation['normalized'] : '';
                $categoryValidation = validateTaskCategoryId($postedCategoryId, $categories, 'edit', $editTaskId);
                $normalizedCategoryId = is_string($categoryValidation['category_id']) ? $categoryValidation['category_id'] : '';
                $repeatRuleValidation = validateRepeatRuleInput($postedRepeatRule, $normalizedDueAt);
                $normalizedRepeatRule = is_string($repeatRuleValidation['normalized']) ? $repeatRuleValidation['normalized'] : '';
                $saveResult = saveUpdatedTask($editTaskId, trim($postedTitle), trim($postedContent), $postedStatus, $postedPriority, $normalizedDueAt, $normalizedRemindAt, $normalizedCategoryId, $normalizedRepeatRule);
                if ($saveResult === 'success') {
                    assignTagsToTask($editTaskId, $postedTagIds);
                    writeDebugLog('task_edit_completed', [
                        'task_id' => $editTaskId,
                        'title_length' => stringLength(trim($postedTitle)),
                        'content_length' => stringLength(trim($postedContent)),
                        'status' => $postedStatus,
                        'priority' => $postedPriority,
                        'category_id' => $normalizedCategoryId,
                        'due_at' => $normalizedDueAt,
                        'remind_at' => $normalizedRemindAt,
                        'tag_ids' => $postedTagIds,
                    ], 'success', [
                        'redirect' => 'index.php?edited=1',
                    ]);
                    header('Location: index.php?edited=1', true, 303);
                    exit;
                }

                if ($saveResult === 'not_found') {
                    $pageErrorMessage = '未找到要保存的任务，任务可能已被删除。';
                    $isEditMode = false;
                } else {
                    $saveErrorMessage = '任务更新失败，请稍后重试。';
                }
            }
        }
    } else {
        writeDebugLog('task_create_submit', [
            'title_length' => stringLength(trim($postedTitle)),
            'content_length' => stringLength(trim($postedContent)),
            'status' => $postedStatus,
            'priority' => $postedPriority,
            'category_id' => $postedCategoryId,
            'submitted_due_at' => $postedDueAt,
            'repeat_rule_type' => $postedRepeatRuleType,
            'repeat_rule_interval' => $postedRepeatRuleInterval,
            'repeat_rule_end_date' => $postedRepeatRuleEndDate,
            'priority_default_applied' => $postedPriorityRaw === '',
        ], 'started');

        $formErrors = validateTaskInput($postedTitle, $postedContent, $postedStatus, $postedPriority, $postedDueAt, $postedRemindAt, $postedCategoryId, $categories, 'create', '', $postedRepeatRule);

        if ($formErrors !== []) {
            writeDebugLog('task_create_validation_failed', [
                'title_length' => stringLength(trim($postedTitle)),
                'content_length' => stringLength(trim($postedContent)),
                'status' => $postedStatus,
                'priority' => $postedPriority,
                'category_id' => $postedCategoryId,
                'submitted_due_at' => $postedDueAt,
                'submitted_remind_at' => $postedRemindAt,
                'repeat_rule_type' => $postedRepeatRuleType,
                'repeat_rule_interval' => $postedRepeatRuleInterval,
                'repeat_rule_end_date' => $postedRepeatRuleEndDate,
            ], 'failed', [
                'errors' => $formErrors,
            ]);
            if (isset($formErrors['priority'])) {
                writeDebugLog('task_priority_invalid_submit', [
                    'submitted_priority' => $postedPriority,
                    'form_action' => 'create',
                ], 'failed', [
                    'reason' => 'priority_outside_allowed_enum',
                    'allowed_priorities' => ALLOWED_PRIORITIES,
                    'database_write_blocked' => true,
                ]);
            }
            if (isset($formErrors['due_at'])) {
                writeDebugLog('task_due_at_invalid_submit', [
                    'submitted_due_at' => $postedDueAt,
                    'form_action' => 'create',
                ], 'failed', [
                    'reason' => 'due_at_validation_failed',
                    'min_due_at' => MIN_DUE_AT,
                    'max_due_at' => MAX_DUE_AT,
                    'database_write_blocked' => true,
                ]);
            }
            if (isset($formErrors['remind_at'])) {
                writeDebugLog('task_remind_at_invalid_submit', [
                    'submitted_remind_at' => $postedRemindAt,
                    'submitted_due_at' => $postedDueAt,
                    'form_action' => 'create',
                ], 'failed', [
                    'reason' => 'remind_at_validation_failed',
                    'min_remind_at' => MIN_REMIND_AT,
                    'max_remind_at' => MAX_REMIND_AT,
                    'database_write_blocked' => true,
                ]);
            }
            if (isset($formErrors['category_id'])) {
                writeDebugLog('task_category_invalid_submit', [
                    'submitted_category_id' => $postedCategoryId,
                    'form_action' => 'create',
                ], 'failed', [
                    'reason' => 'category_validation_failed',
                    'database_write_blocked' => true,
                ]);
            }
            if (isset($formErrors['repeat_rule'])) {
                writeDebugLog('task_repeat_rule_invalid_submit', [
                    'submitted_repeat_rule_type' => $postedRepeatRuleType,
                    'submitted_repeat_rule_interval' => $postedRepeatRuleInterval,
                    'submitted_repeat_rule_end_date' => $postedRepeatRuleEndDate,
                    'form_action' => 'create',
                ], 'failed', [
                    'reason' => 'repeat_rule_validation_failed',
                    'database_write_blocked' => true,
                ]);
            }
        } else {
            $dueAtValidation = validateDueAtInput($postedDueAt);
            $normalizedDueAt = is_string($dueAtValidation['normalized']) ? $dueAtValidation['normalized'] : '';
            $remindAtValidation = validateRemindAtInput($postedRemindAt, $normalizedDueAt);
            $normalizedRemindAt = is_string($remindAtValidation['normalized']) ? $remindAtValidation['normalized'] : '';
            $categoryValidation = validateTaskCategoryId($postedCategoryId, $categories, 'create');
            $normalizedCategoryId = is_string($categoryValidation['category_id']) ? $categoryValidation['category_id'] : '';
            $repeatRuleValidation = validateRepeatRuleInput($postedRepeatRule, $normalizedDueAt);
            $normalizedRepeatRule = is_string($repeatRuleValidation['normalized']) ? $repeatRuleValidation['normalized'] : '';
            $createdAt = date('Y-m-d H:i:s');
            $newTask = [
                'id' => createTaskId(),
                'title' => trim($postedTitle),
                'content' => trim($postedContent),
                'status' => $postedStatus,
                'priority' => $postedPriority,
                'category_id' => $normalizedCategoryId,
                'due_at' => $normalizedDueAt,
                'remind_at' => $normalizedRemindAt,
                'repeat_rule' => $normalizedRepeatRule,
                'priority_default_applied' => $postedPriorityRaw === '',
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];

            if (saveNewTask($newTask)) {
                assignTagsToTask($newTask['id'], $postedTagIds);
                if ($normalizedRemindAt !== '') {
                    saveTaskReminder($newTask['id'], $normalizedRemindAt);
                }
                writeDebugLog('task_create_completed', [
                    'task_id' => $newTask['id'],
                    'title_length' => stringLength($newTask['title']),
                    'content_length' => stringLength($newTask['content']),
                    'status' => $newTask['status'],
                    'priority' => $newTask['priority'],
                    'category_id' => $newTask['category_id'],
                    'due_at' => $newTask['due_at'],
                    'remind_at' => $newTask['remind_at'],
                    'repeat_rule' => $newTask['repeat_rule'],
                    'tag_ids' => $postedTagIds,
                ], 'success', [
                    'redirect' => 'index.php?created=1',
                ]);
                header('Location: index.php?created=1', true, 303);
                exit;
            }

            $saveErrorMessage = '任务保存失败，请稍后重试。';
        }
    }
}

$filterParameters = normalizeFilterParameters($_GET);
$searchKeyword = $filterParameters['keyword'];
$statusFilter = $filterParameters['status'];
$priorityFilter = $filterParameters['priority'];
$tagFilter = $filterParameters['tag_id'];
$filterErrors = $filterParameters['errors'];
$sortParameters = normalizeSortParameters($_GET);
$sortByRawEmpty = !isset($_GET['sort_by']) || trim($_GET['sort_by']) === '';
$sortOrderRawEmpty = !isset($_GET['sort_order']) || trim($_GET['sort_order']) === '';
$sortBy = $sortByRawEmpty ? $effectiveSortField : $sortParameters['sort_by'];
$sortOrder = $sortOrderRawEmpty ? $effectiveSortOrder : $sortParameters['sort_order'];
$sortErrors = $sortParameters['errors'];
$paginationParameters = normalizePaginationParameters($_GET);
$pageSizeRawEmpty = !isset($_GET['page_size']) || trim($_GET['page_size']) === '';
$page = $paginationParameters['page'];
$pageSize = $pageSizeRawEmpty ? $effectivePageSize : $paginationParameters['page_size'];
$paginationErrors = $paginationParameters['errors'];
$hasActiveFilters = $searchKeyword !== '' || $statusFilter !== '' || $priorityFilter !== '' || $tagFilter !== '';

$needsFullTaskList = $isEditRequest || $isViewRequest;
if ($isTrashRequest) {
    $taskListVisibility = TASK_VISIBILITY_TRASH;
} elseif ($isArchiveRequest) {
    $taskListVisibility = TASK_VISIBILITY_ARCHIVED;
} else {
    $taskListVisibility = TASK_VISIBILITY_ACTIVE;
}

if ($needsFullTaskList) {
    if ($isTrashRequest) {
        $visibleTasks = $trashTasks;
    } elseif ($isArchiveRequest) {
        $visibleTasks = $archivedTasks;
    } else {
        $visibleTasks = $activeTasks;
    }
    $totalTaskCount = count($visibleTasks);
    $taskCount = $totalTaskCount;
    $paginationInfo = calculatePagination($totalTaskCount, 1, $totalTaskCount);
    $statusCounts = buildStatusCounts($visibleTasks);
    $visibleStatusCounts = $statusCounts;
} else {
    $loadResult = loadTasksPaginated($page, $pageSize, $sortBy, $sortOrder, $searchKeyword, $statusFilter, $priorityFilter, $tagFilter, $taskListVisibility);
    $visibleTasks = $loadResult['tasks'];
    $paginationInfo = $loadResult['pagination'];
    $totalTaskCount = $paginationInfo['total_count'];
    $taskCount = count($visibleTasks);

    if ($hasActiveFilters) {
        $loadAllResult = loadTasksPaginated(1, PHP_INT_MAX, $sortBy, $sortOrder, $searchKeyword, $statusFilter, $priorityFilter, $tagFilter, $taskListVisibility);
        $statusCounts = buildStatusCounts($loadAllResult['tasks']);
        $visibleStatusCounts = buildStatusCounts($visibleTasks);
    } else {
        if ($taskListVisibility === TASK_VISIBILITY_TRASH) {
            $statusCounts = buildStatusCounts($trashTasks);
        } elseif ($taskListVisibility === TASK_VISIBILITY_ARCHIVED) {
            $statusCounts = buildStatusCounts($archivedTasks);
        } else {
            $statusCounts = buildStatusCounts($activeTasks);
        }
        $visibleStatusCounts = buildStatusCounts($visibleTasks);
    }
}

$categoryCount = count($categories);
$tagCount = count($tags);
$filterAction = isset($_GET['filter_action']) && is_string($_GET['filter_action']) ? trim($_GET['filter_action']) : '';
$isExplicitClearFilter = $filterAction === 'clear';
$hasFilterRequest = isset($_GET['keyword']) || isset($_GET['status']) || isset($_GET['priority']) || isset($_GET['tag_id']) || isset($_GET['sort_by']) || isset($_GET['sort_order']) || $isExplicitClearFilter;

if ($requestMethod === 'GET') {
    if ($isArchiveRequest) {
        writeDebugLog('task_archive_list_view', [
            'keyword_length' => stringLength($searchKeyword),
            'status' => $statusFilter,
            'priority' => $priorityFilter,
            'tag_id' => $tagFilter,
            'page' => $page,
            'page_size' => $pageSize,
            'sort_by' => $sortBy,
            'sort_order' => $sortOrder,
        ], 'success', [
            'archived_total_count' => count($archivedTasks),
            'result_count' => $taskCount,
            'visibility' => TASK_VISIBILITY_ARCHIVED,
        ]);
    }

    if ($isTrashRequest) {
        writeDebugLog('task_trash_list_view', [
            'keyword_length' => stringLength($searchKeyword),
            'status' => $statusFilter,
            'priority' => $priorityFilter,
            'tag_id' => $tagFilter,
            'page' => $page,
            'page_size' => $pageSize,
            'sort_by' => $sortBy,
            'sort_order' => $sortOrder,
        ], 'success', [
            'trash_total_count' => count($trashTasks),
            'result_count' => $taskCount,
            'visibility' => TASK_VISIBILITY_TRASH,
        ]);
    }

    if ($filterErrors !== []) {
        writeDebugLog('task_filter_parameter_exception', [
            'keyword_length' => $filterParameters['raw_keyword_length'],
            'status' => $filterParameters['raw_status'],
            'priority' => $filterParameters['raw_priority'],
            'tag_id' => $filterParameters['raw_tag_id'],
        ], 'failed', [
            'errors' => $filterErrors,
            'normalized_keyword_length' => stringLength($searchKeyword),
            'normalized_status' => $statusFilter,
            'normalized_priority' => $priorityFilter,
            'normalized_tag_id' => $tagFilter,
        ]);
    }

    if ($sortErrors !== []) {
        writeDebugLog('task_sort_parameter_exception', [
            'sort_by' => $sortParameters['raw_sort_by'],
            'sort_order' => $sortParameters['raw_sort_order'],
        ], 'failed', [
            'errors' => $sortErrors,
            'normalized_sort_by' => $sortBy,
            'normalized_sort_order' => $sortOrder,
            'allowed_sort_fields' => ALLOWED_SORT_FIELDS,
            'allowed_sort_orders' => ALLOWED_SORT_ORDERS,
        ]);
    }

    if ($hasFilterRequest) {
        if ($isExplicitClearFilter || !$hasActiveFilters) {
            $operation = 'task_filter_clear';
        } elseif ($searchKeyword !== '' && $statusFilter !== '' && $priorityFilter !== '') {
            $operation = 'task_filter_search_status_priority_execute';
        } elseif ($searchKeyword !== '' && $statusFilter !== '') {
            $operation = 'task_filter_search_and_status_execute';
        } elseif ($searchKeyword !== '' && $priorityFilter !== '') {
            $operation = 'task_filter_search_and_priority_execute';
        } elseif ($statusFilter !== '' && $priorityFilter !== '') {
            $operation = 'task_filter_status_and_priority_execute';
        } elseif ($searchKeyword !== '') {
            $operation = 'task_filter_search_execute';
        } elseif ($priorityFilter !== '') {
            $operation = 'task_filter_priority_execute';
        } else {
            $operation = 'task_filter_status_execute';
        }

        if (isset($_GET['sort_by']) || isset($_GET['sort_order'])) {
            $operation .= '_with_sort';
        }

        writeDebugLog($operation, [
            'keyword_length' => stringLength($searchKeyword),
            'status' => $statusFilter,
            'priority' => $priorityFilter,
            'sort_by' => $sortBy,
            'sort_order' => $sortOrder,
            'filter_action' => $filterAction,
        ], 'success', [
            'total_task_count' => $totalTaskCount,
            'result_count' => $taskCount,
            'sort' => $sortBy . '_' . $sortOrder,
            'keyword_empty' => $searchKeyword === '',
            'status_empty' => $statusFilter === '',
            'priority_empty' => $priorityFilter === '',
        ]);

        if ($taskCount === 0 && $totalTaskCount > 0) {
            writeDebugLog('task_filter_no_result', [
                'keyword_length' => stringLength($searchKeyword),
                'status' => $statusFilter,
                'priority' => $priorityFilter,
                'sort_by' => $sortBy,
                'sort_order' => $sortOrder,
            ], 'success', [
                'total_task_count' => $totalTaskCount,
                'result_count' => 0,
            ]);
        }
    } elseif (isset($_GET['sort_by']) || isset($_GET['sort_order'])) {
        writeDebugLog('task_sort_change', [
            'sort_by' => $sortBy,
            'sort_order' => $sortOrder,
            'previous_sort_by' => DEFAULT_SORT_FIELD,
            'previous_sort_order' => DEFAULT_SORT_ORDER,
        ], 'success', [
            'total_task_count' => $totalTaskCount,
            'result_count' => $taskCount,
            'sort_changed' => $sortBy !== DEFAULT_SORT_FIELD || $sortOrder !== DEFAULT_SORT_ORDER,
        ]);
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>待办任务管理</title>
    <style>
        :root {
            color-scheme: light;
            --page-bg: #f5f7fb;
            --panel-bg: #ffffff;
            --text-main: #1f2937;
            --text-muted: #667085;
            --border: #d9e1ec;
            --accent: #2563eb;
            --accent-strong: #1d4ed8;
            --accent-soft: #e8f0ff;
            --danger-bg: #fef2f2;
            --danger-border: #fecaca;
            --danger-text: #b42318;
            --success-bg: #ecfdf3;
            --success-border: #abefc6;
            --success-text: #067647;
            --status-bg: #ecfdf3;
            --status-text: #067647;
            --shadow: 0 18px 50px rgba(31, 41, 55, 0.08);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            background: var(--page-bg);
            color: var(--text-main);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "Microsoft YaHei", sans-serif;
            line-height: 1.5;
        }

        .page {
            width: min(1120px, calc(100% - 32px));
            margin: 0 auto;
            padding: 48px 0;
        }

        .page-header {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 24px;
            margin-bottom: 24px;
        }

        .page-title {
            margin: 0 0 8px;
            font-size: 32px;
            font-weight: 700;
            letter-spacing: 0;
        }

        .page-subtitle {
            margin: 0;
            color: var(--text-muted);
            font-size: 15px;
        }

        .task-count {
            flex: 0 0 auto;
            padding: 8px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--panel-bg);
            color: var(--text-muted);
            font-size: 14px;
        }

        .modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: var(--panel-bg);
            border-radius: 12px;
            padding: 24px;
            width: min(480px, calc(100% - 48px));
            max-height: calc(100vh - 96px);
            overflow-y: auto;
            box-shadow: var(--shadow);
        }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        .modal-header h2 {
            margin: 0;
            font-size: 20px;
            font-weight: 600;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--text-muted);
            padding: 0;
            line-height: 1;
        }

        .modal-close:hover {
            color: var(--text-main);
        }

        .dashboard-panel {
            margin-bottom: 24px;
            padding: 20px 24px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--panel-bg);
            box-shadow: var(--shadow);
        }

        .dashboard-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 18px;
        }

        .dashboard-title {
            margin: 0 0 6px;
            font-size: 20px;
            font-weight: 750;
            letter-spacing: 0;
        }

        .dashboard-subtitle {
            margin: 0;
            color: var(--text-muted);
            font-size: 14px;
        }

        .dashboard-refresh-time {
            flex: 0 0 auto;
            color: var(--text-muted);
            font-size: 13px;
            text-align: right;
        }

        .dashboard-metrics {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 18px;
        }

        .dashboard-metric {
            min-width: 0;
            padding: 14px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #f8fafc;
        }

        .dashboard-metric-label {
            margin: 0 0 6px;
            color: var(--text-muted);
            font-size: 13px;
            font-weight: 650;
        }

        .dashboard-metric-value {
            margin: 0;
            color: var(--text-main);
            font-size: 28px;
            font-weight: 800;
            line-height: 1.15;
        }

        .dashboard-metric.overdue .dashboard-metric-value {
            color: var(--danger-text);
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) minmax(260px, 1.2fr);
            gap: 16px;
        }

        .dashboard-block {
            min-width: 0;
        }

        .dashboard-block h3 {
            margin: 0 0 12px;
            font-size: 15px;
            font-weight: 750;
            letter-spacing: 0;
        }

        .distribution-list,
        .upcoming-list {
            display: grid;
            gap: 10px;
            margin: 0;
            padding: 0;
            list-style: none;
        }

        .distribution-item {
            display: grid;
            grid-template-columns: minmax(64px, 84px) 1fr auto;
            align-items: center;
            gap: 10px;
            color: var(--text-muted);
            font-size: 13px;
        }

        .distribution-bar {
            height: 8px;
            overflow: hidden;
            border-radius: 999px;
            background: #e5e7eb;
        }

        .distribution-fill {
            display: block;
            height: 100%;
            min-width: 0;
            border-radius: inherit;
            background: var(--accent);
        }

        .distribution-count {
            min-width: 28px;
            color: var(--text-main);
            font-weight: 750;
            text-align: right;
        }

        .upcoming-item {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 6px 12px;
            padding: 10px 0;
            border-bottom: 1px solid var(--border);
        }

        .upcoming-item:last-child {
            border-bottom: 0;
        }

        .upcoming-title {
            min-width: 0;
            overflow: hidden;
            color: var(--text-main);
            font-size: 14px;
            font-weight: 700;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .upcoming-meta {
            color: var(--text-muted);
            font-size: 13px;
        }

        .dashboard-empty {
            margin: 0;
            padding: 16px;
            border: 1px dashed var(--border);
            border-radius: 8px;
            color: var(--text-muted);
            background: #f8fafc;
            font-size: 14px;
        }

        .calendar-panel {
            margin-bottom: 24px;
            padding: 20px 24px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--panel-bg);
            box-shadow: var(--shadow);
        }

        .calendar-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 16px;
        }

        .calendar-title {
            margin: 0 0 6px;
            font-size: 20px;
            font-weight: 750;
            letter-spacing: 0;
        }

        .calendar-subtitle {
            margin: 0;
            color: var(--text-muted);
            font-size: 14px;
        }

        .calendar-nav {
            display: flex;
            flex: 0 0 auto;
            align-items: center;
            gap: 8px;
        }

        .calendar-month-label {
            min-width: 112px;
            color: var(--text-main);
            font-size: 15px;
            font-weight: 750;
            text-align: center;
        }

        .calendar-layout {
            display: grid;
            grid-template-columns: minmax(0, 1.4fr) minmax(280px, 0.8fr);
            gap: 18px;
            align-items: start;
        }

        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, minmax(0, 1fr));
            gap: 1px;
            overflow: hidden;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--border);
        }

        .calendar-weekday,
        .calendar-day {
            min-width: 0;
            background: #ffffff;
        }

        .calendar-weekday {
            padding: 10px 8px;
            color: var(--text-muted);
            font-size: 12px;
            font-weight: 750;
            text-align: center;
        }

        .calendar-day {
            min-height: 118px;
            padding: 8px;
        }

        .calendar-day.empty {
            background: #f8fafc;
        }

        .calendar-day-link {
            display: flex;
            width: 100%;
            min-height: 100%;
            flex-direction: column;
            gap: 6px;
            border: 0;
            color: inherit;
            text-decoration: none;
        }

        .calendar-day-link:hover .calendar-day-number,
        .calendar-day-link:focus .calendar-day-number {
            color: var(--accent-strong);
        }

        .calendar-day.selected {
            background: var(--accent-soft);
            box-shadow: inset 0 0 0 2px var(--accent);
        }

        .calendar-day.today .calendar-day-number {
            background: var(--accent);
            color: #ffffff;
        }

        .calendar-day-number {
            display: inline-flex;
            width: 28px;
            height: 28px;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            font-size: 13px;
            font-weight: 800;
        }

        .calendar-event-count {
            display: inline-flex;
            width: fit-content;
            max-width: 100%;
            align-items: center;
            padding: 3px 7px;
            border-radius: 999px;
            background: #eef2ff;
            color: #3730a3;
            font-size: 12px;
            font-weight: 700;
        }

        .calendar-event-type-row {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
        }

        .calendar-type-badge {
            display: inline-flex;
            align-items: center;
            padding: 2px 6px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 750;
        }

        .calendar-type-badge.due {
            background: #fef3c7;
            color: #92400e;
        }

        .calendar-type-badge.remind {
            background: #dcfce7;
            color: #166534;
        }

        .calendar-summary-list {
            display: grid;
            gap: 4px;
            margin: 0;
            padding: 0;
            list-style: none;
        }

        .calendar-summary-item {
            overflow: hidden;
            color: var(--text-muted);
            font-size: 12px;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .calendar-detail {
            min-width: 0;
            padding: 16px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #f8fafc;
        }

        .calendar-detail-header {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 12px;
        }

        .calendar-detail-title {
            margin: 0;
            font-size: 16px;
            font-weight: 800;
            letter-spacing: 0;
        }

        .calendar-detail-count {
            color: var(--text-muted);
            font-size: 13px;
            font-weight: 700;
            white-space: nowrap;
        }

        .calendar-task-list {
            display: grid;
            max-height: 520px;
            gap: 10px;
            margin: 0;
            padding: 0;
            overflow: auto;
            list-style: none;
        }

        .calendar-task-item {
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #ffffff;
        }

        .calendar-task-topline {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
        }

        .calendar-task-title {
            min-width: 0;
            color: var(--text-main);
            font-size: 14px;
            font-weight: 800;
            text-decoration: none;
            overflow-wrap: anywhere;
        }

        .calendar-task-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            color: var(--text-muted);
            font-size: 12px;
        }

        .calendar-empty {
            margin: 0;
            padding: 18px;
            border: 1px dashed var(--border);
            border-radius: 8px;
            color: var(--text-muted);
            background: #ffffff;
            font-size: 14px;
        }

        .status-stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 24px;
        }

        .status-stat {
            padding: 16px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--panel-bg);
            box-shadow: var(--shadow);
        }

        .status-stat-label {
            margin: 0 0 6px;
            color: var(--text-muted);
            font-size: 13px;
            font-weight: 650;
        }

        .status-stat-value {
            margin: 0;
            color: var(--text-main);
            font-size: 26px;
            font-weight: 750;
        }

        .filter-panel {
            margin-bottom: 24px;
            padding: 20px 24px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--panel-bg);
            box-shadow: var(--shadow);
        }

        .filter-panel h2 {
            margin: 0 0 16px;
            font-size: 20px;
            letter-spacing: 0;
        }

        .bulk-panel {
            margin-bottom: 24px;
            padding: 18px 20px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--panel-bg);
            box-shadow: var(--shadow);
        }

        .bulk-panel h2 {
            margin: 0 0 14px;
            font-size: 20px;
            letter-spacing: 0;
        }

        .bulk-toolbar {
            display: grid;
            grid-template-columns: minmax(120px, 160px) minmax(140px, 190px) minmax(120px, 160px) repeat(5, auto);
            gap: 10px;
            align-items: end;
        }

        .bulk-selection-count {
            align-self: center;
            color: var(--text-muted);
            font-size: 14px;
            font-weight: 650;
        }

        .bulk-hidden-selected {
            display: none;
        }

        .bulk-select-cell {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .bulk-checkbox {
            width: 18px;
            height: 18px;
            accent-color: var(--accent);
            cursor: pointer;
        }

        .bulk-button {
            display: inline-flex;
            min-height: 38px;
            align-items: center;
            justify-content: center;
            padding: 8px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #ffffff;
            color: var(--text-main);
            cursor: pointer;
            font: inherit;
            font-size: 13px;
            font-weight: 700;
            white-space: nowrap;
        }

        .bulk-button:hover,
        .bulk-button:focus {
            border-color: var(--accent);
            background: var(--accent-soft);
            color: var(--accent-strong);
            outline: none;
        }

        .bulk-button.danger {
            border-color: var(--danger-border);
            color: var(--danger-text);
        }

        .bulk-button.danger:hover,
        .bulk-button.danger:focus {
            background: var(--danger-bg);
            color: var(--danger-text);
        }

        .bulk-button:disabled {
            border-color: var(--border);
            background: #f2f4f7;
            color: #98a2b3;
            cursor: not-allowed;
        }

        .import-panel {
            margin-bottom: 24px;
            padding: 18px 20px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--panel-bg);
            box-shadow: var(--shadow);
        }

        .import-panel h2 {
            margin: 0 0 14px;
            font-size: 20px;
            letter-spacing: 0;
        }

        .import-form {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 14px;
            align-items: end;
        }

        .import-format-help {
            margin-top: 10px;
            padding: 12px 14px;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: var(--page-bg);
            font-size: 13px;
            line-height: 1.7;
            color: var(--text-muted);
        }

        .import-format-help p {
            margin: 0;
        }

        .export-panel {
            margin-bottom: 24px;
            padding: 18px 20px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--panel-bg);
            box-shadow: var(--shadow);
        }

        .export-panel h2 {
            margin: 0 0 14px;
            font-size: 20px;
            letter-spacing: 0;
        }

        .export-form {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 14px;
            align-items: end;
        }

        .export-scope-info {
            margin-top: 10px;
            padding: 12px 14px;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: var(--page-bg);
            font-size: 13px;
            line-height: 1.7;
            color: var(--text-muted);
        }

        .export-scope-info p {
            margin: 0;
        }

        .export-fields-info {
            margin-top: 10px;
            padding: 12px 14px;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: var(--page-bg);
            font-size: 13px;
            line-height: 1.7;
            color: var(--text-muted);
        }

        .export-fields-info p {
            margin: 0;
        }

        .backup-panel {
            margin-bottom: 24px;
            padding: 18px 20px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--panel-bg);
            box-shadow: var(--shadow);
        }

        .backup-panel h2 {
            margin: 0 0 14px;
            font-size: 20px;
            letter-spacing: 0;
        }

        .backup-restore-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .backup-section,
        .restore-section {
            padding: 14px;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: var(--page-bg);
        }

        .backup-section h3,
        .restore-section h3 {
            margin: 0 0 8px;
            font-size: 15px;
            font-weight: 600;
        }

        .backup-description,
        .restore-description {
            margin: 0 0 12px;
            font-size: 13px;
            line-height: 1.6;
            color: var(--text-muted);
        }

        .backup-form,
        .restore-form {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .filter-form {
            display: grid;
            grid-template-columns: minmax(200px, 1fr) minmax(130px, 160px) minmax(130px, 160px) minmax(130px, 160px) minmax(130px, 150px) minmax(130px, 150px) auto auto;
            gap: 12px;
            align-items: end;
        }

        .filter-summary {
            margin: 14px 0 0;
            color: var(--text-muted);
            font-size: 14px;
        }

        .create-panel {
            margin-bottom: 24px;
            padding: 24px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--panel-bg);
            box-shadow: var(--shadow);
        }

        .create-panel h2 {
            margin: 0 0 18px;
            font-size: 22px;
            letter-spacing: 0;
        }

        .category-panel {
            margin-bottom: 24px;
            padding: 24px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--panel-bg);
            box-shadow: var(--shadow);
        }

        .category-panel h2 {
            margin: 0 0 18px;
            font-size: 22px;
            letter-spacing: 0;
        }

        .category-create-form {
            display: grid;
            grid-template-columns: minmax(220px, 1fr) auto;
            gap: 12px;
            align-items: end;
            margin-bottom: 18px;
        }

        .category-list {
            display: grid;
            gap: 10px;
        }

        .category-row {
            display: grid;
            grid-template-columns: minmax(160px, 1fr) 112px minmax(260px, 1.2fr) auto;
            gap: 12px;
            align-items: center;
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #f8fafc;
        }

        .category-name {
            margin: 0;
            font-size: 15px;
            font-weight: 750;
            overflow-wrap: anywhere;
        }

        .category-meta {
            color: var(--text-muted);
            font-size: 13px;
            white-space: nowrap;
        }

        .category-edit-form {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .category-edit-form .input {
            min-height: 38px;
            padding: 8px 10px;
        }

        .category-actions {
            display: flex;
            gap: 8px;
            justify-content: flex-end;
        }

        .category-badge {
            display: inline-flex;
            width: fit-content;
            max-width: 100%;
            align-items: center;
            padding: 5px 10px;
            border: 1px solid #bfdbfe;
            border-radius: 999px;
            background: #eff6ff;
            color: #175cd3;
            font-size: 13px;
            font-weight: 700;
            overflow-wrap: anywhere;
        }

        .category-empty {
            color: var(--text-muted);
            font-size: 13px;
        }

        .tag-panel {
            margin-bottom: 24px;
            padding: 24px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--panel-bg);
            box-shadow: var(--shadow);
        }

        .tag-panel h2 {
            margin: 0 0 18px;
            font-size: 22px;
            letter-spacing: 0;
        }

        .tag-create-form {
            display: grid;
            grid-template-columns: minmax(180px, 1fr) minmax(120px, 180px) auto;
            gap: 12px;
            align-items: end;
            margin-bottom: 18px;
        }

        .tag-list {
            display: grid;
            gap: 10px;
        }

        .tag-row {
            display: grid;
            grid-template-columns: minmax(160px, 1fr) minmax(100px, 140px) minmax(260px, 1.2fr) auto;
            gap: 12px;
            align-items: center;
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #f8fafc;
        }

        .tag-name {
            margin: 0;
            font-size: 15px;
            font-weight: 750;
            overflow-wrap: anywhere;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .tag-color-dot {
            width: 14px;
            height: 14px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        .tag-meta {
            color: var(--text-muted);
            font-size: 13px;
            white-space: nowrap;
        }

        .tag-edit-form {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .tag-edit-form .input {
            min-height: 38px;
            padding: 8px 10px;
        }

        .tag-actions {
            display: flex;
            gap: 8px;
            justify-content: flex-end;
        }

        .tag-badge {
            display: inline-flex;
            width: fit-content;
            max-width: 100%;
            align-items: center;
            padding: 4px 10px;
            border-radius: 999px;
            background: #f1f5f9;
            font-size: 12px;
            font-weight: 600;
            overflow-wrap: anywhere;
        }

        .tag-empty {
            color: var(--text-muted);
            font-size: 13px;
        }

        .tag-selector {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            padding: 8px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #ffffff;
            min-height: 44px;
            align-items: center;
        }

        .tag-selector-item {
            display: flex;
            align-items: center;
            gap: 4px;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 13px;
            cursor: pointer;
        }

        .tag-selector-item input[type="checkbox"] {
            width: auto;
            min-height: auto;
            margin: 0;
            cursor: pointer;
        }

        .form-grid {
            display: grid;
            grid-template-columns: minmax(220px, 1fr) minmax(140px, 180px) minmax(140px, 180px) minmax(150px, 190px) minmax(190px, 240px);
            gap: 18px;
        }

        .form-field {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .form-field.full-width {
            grid-column: 1 / -1;
        }

        .form-field label {
            color: var(--text-main);
            font-size: 14px;
            font-weight: 650;
        }

        .input,
        .textarea,
        .select {
            width: 100%;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #ffffff;
            color: var(--text-main);
            font: inherit;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }

        .input,
        .select {
            min-height: 44px;
            padding: 10px 12px;
        }

        .textarea {
            min-height: 128px;
            resize: vertical;
            padding: 12px;
        }

        .input:focus,
        .textarea:focus,
        .select:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.14);
            outline: none;
        }

        .field-help {
            color: var(--text-muted);
            font-size: 13px;
        }

        .field-error {
            color: var(--danger-text);
            font-size: 13px;
            font-weight: 600;
        }

        .input.has-error,
        .textarea.has-error,
        .select.has-error {
            border-color: var(--danger-border);
            background: #fffafa;
        }

        .form-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-top: 18px;
        }

        .primary-button {
            display: inline-flex;
            min-height: 44px;
            align-items: center;
            justify-content: center;
            border: 0;
            border-radius: 8px;
            background: var(--accent);
            color: #ffffff;
            cursor: pointer;
            font: inherit;
            font-size: 15px;
            font-weight: 700;
            padding: 10px 18px;
        }

        .primary-button:hover,
        .primary-button:focus {
            background: var(--accent-strong);
            outline: none;
        }

        .secondary-button {
            display: inline-flex;
            min-height: 44px;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #ffffff;
            color: var(--text-main);
            font-size: 15px;
            font-weight: 700;
            padding: 10px 18px;
            text-decoration: none;
        }

        .secondary-button:hover,
        .secondary-button:focus {
            border-color: var(--accent);
            background: var(--accent-soft);
            color: var(--accent-strong);
            outline: none;
        }

        .feedback {
            margin: 0 0 18px;
            padding: 12px 14px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 650;
        }

        .feedback.success {
            border: 1px solid var(--success-border);
            background: var(--success-bg);
            color: var(--success-text);
        }

        .feedback.error {
            border: 1px solid var(--danger-border);
            background: var(--danger-bg);
            color: var(--danger-text);
        }

        .task-panel {
            overflow: hidden;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--panel-bg);
            box-shadow: var(--shadow);
        }

        .task-panel-header {
            display: grid;
            grid-template-columns: 44px minmax(140px, 0.85fr) minmax(160px, 1.05fr) 92px 64px 104px 126px 116px 142px;
            gap: 16px;
            padding: 14px 20px;
            border-bottom: 1px solid var(--border);
            background: #f8fafc;
            color: var(--text-muted);
            font-size: 13px;
            font-weight: 600;
        }

        .task-row {
            display: grid;
            grid-template-columns: 44px minmax(140px, 0.85fr) minmax(160px, 1.05fr) 92px 64px 104px 126px 116px 142px;
            gap: 16px;
            align-items: center;
            padding: 18px 20px;
            border-bottom: 1px solid var(--border);
        }

        .task-panel-header.trash-panel-header,
        .task-row.trash-task-row {
            grid-template-columns: minmax(140px, 0.95fr) minmax(160px, 1.1fr) 92px 64px 104px 126px 116px 180px;
        }

        .task-row:last-child {
            border-bottom: 0;
        }

        .task-title {
            margin: 0;
            font-size: 16px;
            font-weight: 650;
            overflow-wrap: anywhere;
        }

        .task-summary {
            margin: 4px 0 0;
            color: var(--text-muted);
            font-size: 14px;
            overflow-wrap: anywhere;
        }

        .status-badge {
            display: inline-flex;
            width: fit-content;
            max-width: 100%;
            align-items: center;
            padding: 5px 10px;
            border-radius: 999px;
            background: var(--status-bg);
            color: var(--status-text);
            font-size: 13px;
            font-weight: 650;
            overflow-wrap: anywhere;
        }

        .priority-badge {
            display: inline-flex;
            width: fit-content;
            max-width: 100%;
            align-items: center;
            justify-content: center;
            min-width: 44px;
            padding: 5px 10px;
            border: 1px solid transparent;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 750;
            overflow-wrap: anywhere;
        }

        .priority-high {
            border-color: #fecaca;
            background: #fef2f2;
            color: #b42318;
        }

        .priority-medium {
            border-color: #fedf89;
            background: #fffaeb;
            color: #b54708;
        }

        .priority-low {
            border-color: #bfdbfe;
            background: #eff6ff;
            color: #175cd3;
        }

        .task-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            align-items: center;
        }

        .pagination-container {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            padding: 16px 20px;
            border-top: 1px solid var(--border);
            background: #f8fafc;
        }

        .pagination-info {
            color: var(--text-muted);
            font-size: 14px;
        }

        .pagination-controls {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
        }

        .pagination-page-input {
            width: 64px;
            min-height: 36px;
            padding: 6px 8px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #ffffff;
            color: var(--text-main);
            font: inherit;
            font-size: 14px;
            text-align: center;
        }

        .pagination-page-input:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.14);
            outline: none;
        }

        .pagination-button {
            display: inline-flex;
            min-height: 36px;
            align-items: center;
            justify-content: center;
            padding: 6px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #ffffff;
            color: var(--text-main);
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
        }

        .pagination-button:hover:not(:disabled) {
            border-color: var(--accent);
            background: var(--accent-soft);
            color: var(--accent-strong);
            outline: none;
        }

        .pagination-button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .pagination-button.current {
            border-color: var(--accent);
            background: var(--accent);
            color: #ffffff;
        }

        .pagination-button.current:hover {
            background: var(--accent-strong);
        }

        .pagination-size-select {
            min-height: 36px;
            padding: 6px 8px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #ffffff;
            color: var(--text-main);
            font: inherit;
            font-size: 14px;
        }

        .pagination-size-select:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.14);
            outline: none;
        }

        .pagination-errors {
            padding: 12px 20px;
            border-top: 1px solid var(--border);
            background: var(--danger-bg);
        }

        .page-size-selector {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .page-size-selector label {
            color: var(--text-muted);
            font-size: 14px;
            white-space: nowrap;
        }

        .created-time {
            color: var(--text-muted);
            font-size: 14px;
            white-space: nowrap;
        }

        .due-cell {
            display: flex;
            flex-direction: column;
            gap: 6px;
            align-items: flex-start;
        }

        .due-badge {
            display: inline-flex;
            width: fit-content;
            max-width: 100%;
            align-items: center;
            padding: 5px 10px;
            border: 1px solid transparent;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 750;
            overflow-wrap: anywhere;
        }

        .due-overdue {
            border-color: #fecaca;
            background: #fef2f2;
            color: #b42318;
        }

        .due-today {
            border-color: #fedf89;
            background: #fffaeb;
            color: #b54708;
        }

        .due-future {
            border-color: #bbf7d0;
            background: #f0fdf4;
            color: #047857;
        }

        .due-none {
            border-color: #d9e1ec;
            background: #f8fafc;
            color: var(--text-muted);
        }

        .due-invalid {
            border-color: var(--danger-border);
            background: var(--danger-bg);
            color: var(--danger-text);
        }

        .remind-badge {
            display: inline-flex;
            width: fit-content;
            max-width: 100%;
            align-items: center;
            padding: 5px 10px;
            border: 1px solid transparent;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 750;
            overflow-wrap: anywhere;
        }

        .remind-active {
            border-color: #fecaca;
            background: #fef2f2;
            color: #b42318;
        }

        .remind-pending {
            border-color: #bbf7d0;
            background: #f0fdf4;
            color: #047857;
        }

        .remind-completed {
            border-color: #d9e1ec;
            background: #f8fafc;
            color: var(--text-muted);
        }

        .remind-none {
            border-color: #d9e1ec;
            background: #f8fafc;
            color: var(--text-muted);
        }

        .remind-invalid {
            border-color: var(--danger-border);
            background: var(--danger-bg);
            color: var(--danger-text);
        }

        .remind-time {
            color: var(--text-muted);
            font-size: 13px;
            line-height: 1.35;
        }

        .repeat-badge {
            display: inline-flex;
            width: fit-content;
            max-width: 100%;
            align-items: center;
            padding: 5px 10px;
            border: 1px solid transparent;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 750;
            overflow-wrap: anywhere;
        }

        .repeat-active {
            border-color: #dbeafe;
            background: #eff6ff;
            color: #1d4ed8;
        }

        .repeat-none {
            border-color: #d9e1ec;
            background: #f8fafc;
            color: var(--text-muted);
        }

        .due-time {
            color: var(--text-muted);
            font-size: 13px;
            line-height: 1.35;
        }

        .status-cell {
            display: flex;
            flex-direction: column;
            gap: 8px;
            align-items: flex-start;
        }

        .remind-cell {
            display: flex;
            flex-direction: column;
            gap: 6px;
            align-items: flex-start;
        }

        .status-change-form {
            display: flex;
            width: 100%;
            gap: 6px;
        }

        .status-select {
            min-width: 0;
            flex: 1 1 auto;
            min-height: 34px;
            padding: 6px 8px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #ffffff;
            color: var(--text-main);
            font: inherit;
            font-size: 13px;
        }

        .status-button {
            display: inline-flex;
            flex: 0 0 auto;
            min-height: 34px;
            align-items: center;
            justify-content: center;
            padding: 7px 10px;
            border: 1px solid var(--accent);
            border-radius: 8px;
            background: #ffffff;
            color: var(--accent);
            cursor: pointer;
            font: inherit;
            font-size: 13px;
            font-weight: 650;
        }

        .status-button:hover,
        .status-button:focus {
            background: var(--accent-soft);
            outline: none;
        }

        .detail-panel {
            margin-bottom: 24px;
            padding: 24px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--panel-bg);
            box-shadow: var(--shadow);
        }

        .detail-panel h2 {
            margin: 0 0 16px;
            font-size: 22px;
            letter-spacing: 0;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
            margin-bottom: 18px;
        }

        .detail-item {
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #f8fafc;
        }

        .detail-label {
            display: block;
            margin-bottom: 5px;
            color: var(--text-muted);
            font-size: 12px;
            font-weight: 700;
        }

        .detail-value {
            color: var(--text-main);
            font-size: 14px;
            overflow-wrap: anywhere;
        }

        .detail-content {
            margin: 0;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }

        .detail-item-full {
            grid-column: 1 / -1;
        }

        .detail-section {
            margin-top: 18px;
            padding-top: 18px;
            border-top: 1px solid var(--border);
        }

        .detail-section h3 {
            margin: 0 0 12px;
            font-size: 16px;
            font-weight: 700;
            color: var(--text-main);
        }

        .detail-empty {
            margin: 0;
            color: var(--text-muted);
            font-size: 14px;
        }

        .task-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 4px;
        }

        .tag-badge {
            display: inline-flex;
            align-items: center;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }

        .subtask-list,
        .comment-list,
        .history-list,
        .attachment-list,
        .recurrence-list {
            list-style: none;
            margin: 0;
            padding: 0;
        }

        .recurrence-item {
            display: flex;
            flex-direction: column;
            gap: 8px;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 6px;
            margin-bottom: 8px;
            background: #f8fafc;
        }

        .recurrence-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px 14px;
            color: var(--text-muted);
            font-size: 12px;
            overflow-wrap: anywhere;
        }

        .subtask-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border: 1px solid var(--border);
            border-radius: 6px;
            margin-bottom: 6px;
            background: #f8fafc;
        }

        .subtask-item.completed {
            opacity: 0.7;
        }

        .subtask-item.completed .subtask-title {
            text-decoration: line-through;
        }

        .subtask-checkbox {
            font-size: 14px;
        }

        .subtask-title {
            flex: 1;
            font-size: 14px;
            color: var(--text-main);
        }

        .subtask-time {
            font-size: 12px;
            color: var(--text-muted);
            margin-right: 8px;
        }

        .subtask-progress {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
        }

        .subtask-progress-bar {
            flex: 1;
            height: 6px;
            background: #e2e8f0;
            border-radius: 3px;
            overflow: hidden;
        }

        .subtask-progress-fill {
            height: 100%;
            background: #22c55e;
            border-radius: 3px;
            transition: width 0.3s ease;
        }

        .subtask-progress-text {
            font-size: 12px;
            color: var(--text-muted);
            white-space: nowrap;
        }

        .subtask-add-form {
            margin-bottom: 12px;
        }

        .subtask-form-inline {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .subtask-input {
            flex: 1;
            min-width: 150px;
        }

        .subtask-add-btn {
            padding: 6px 12px;
            font-size: 13px;
        }

        .subtask-actions {
            display: flex;
            gap: 4px;
            margin-left: auto;
            opacity: 0;
            transition: opacity 0.2s ease;
        }

        .subtask-item:hover .subtask-actions {
            opacity: 1;
        }

        .subtask-action-btn {
            background: none;
            border: none;
            cursor: pointer;
            padding: 2px 4px;
            font-size: 12px;
            border-radius: 4px;
            transition: background-color 0.2s ease;
        }

        .subtask-action-btn:hover {
            background-color: #e2e8f0;
        }

        .subtask-checkbox-btn {
            background: none;
            border: none;
            cursor: pointer;
            font-size: 16px;
            padding: 0;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
            transition: background-color 0.2s ease;
        }

        .subtask-checkbox-btn:hover {
            background-color: #e2e8f0;
        }

        .subtask-edit-form {
            flex: 1;
        }

        .subtask-edit-input {
            flex: 1;
            min-width: 100px;
            padding: 4px 8px;
            font-size: 14px;
        }

        .subtask-save-btn,
        .subtask-cancel-btn {
            padding: 4px 8px;
            font-size: 12px;
        }

        .subtask-title.completed {
            text-decoration: line-through;
            color: var(--text-muted);
        }

        .comment-item {
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 6px;
            margin-bottom: 8px;
            background: #f8fafc;
        }

        .comment-content {
            font-size: 14px;
            color: var(--text-main);
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }

        .comment-time {
            margin-top: 6px;
            font-size: 12px;
            color: var(--text-muted);
        }

        .comment-add-form {
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid var(--border);
        }

        .comment-textarea {
            width: 100%;
            resize: vertical;
            min-height: 80px;
            padding: 10px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
            box-sizing: border-box;
        }

        .comment-textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .comment-add-btn {
            margin-top: 10px;
        }

        .attachment-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 6px;
            margin-bottom: 8px;
            background: #f8fafc;
        }

        .attachment-info {
            display: flex;
            align-items: center;
            gap: 8px;
            flex: 1;
            min-width: 0;
        }

        .attachment-icon {
            font-size: 16px;
        }

        .attachment-name {
            font-size: 14px;
            color: var(--text-main);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 200px;
        }

        .attachment-meta {
            font-size: 12px;
            color: var(--text-muted);
            white-space: nowrap;
        }

        .attachment-time {
            font-size: 12px;
            color: var(--text-muted);
            white-space: nowrap;
        }

        .attachment-delete-form {
            margin: 0;
        }

        .attachment-delete-btn {
            padding: 4px 10px;
            font-size: 12px;
        }

        .attachment-add-form {
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid var(--border);
        }

        .attachment-add-form .form-field {
            margin-bottom: 10px;
        }

        .history-item {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr) auto;
            gap: 10px;
            align-items: start;
            padding: 10px 0;
            border-bottom: 1px solid var(--border);
            font-size: 13px;
        }

        .history-item:last-child {
            border-bottom: none;
        }

        .history-status {
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .history-status.started {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .history-status.success {
            background: #dcfce7;
            color: #15803d;
        }

        .history-status.failed {
            background: #fee2e2;
            color: #b91c1c;
        }

        .history-operation {
            color: var(--text-main);
            font-weight: 650;
        }

        .history-changes {
            grid-column: 2 / 4;
            display: flex;
            flex-direction: column;
            gap: 4px;
            margin: 0;
            padding: 0;
            list-style: none;
            color: var(--text-muted);
            font-size: 12px;
            overflow-wrap: anywhere;
        }

        .history-change strong {
            color: var(--text-main);
            font-weight: 650;
        }

        .history-time {
            color: var(--text-muted);
            font-size: 12px;
            white-space: nowrap;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .action-link {
            display: inline-flex;
            min-height: 34px;
            align-items: center;
            justify-content: center;
            padding: 7px 10px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #ffffff;
            color: var(--accent);
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
        }

        .delete-form {
            margin: 0;
        }

        .delete-button {
            display: inline-flex;
            min-height: 34px;
            align-items: center;
            justify-content: center;
            padding: 7px 10px;
            border: 1px solid var(--danger-border);
            border-radius: 8px;
            background: #ffffff;
            color: var(--danger-text);
            cursor: pointer;
            font: inherit;
            font-size: 13px;
            font-weight: 600;
        }

        .action-link:hover,
        .action-link:focus,
        .delete-button:hover,
        .delete-button:focus {
            border-color: var(--accent);
            background: var(--accent-soft);
            outline: none;
        }

        .delete-button:hover,
        .delete-button:focus {
            border-color: var(--danger-border);
            background: var(--danger-bg);
        }

        .empty-state {
            padding: 72px 24px;
            text-align: center;
        }

        .empty-state h2 {
            margin: 0 0 10px;
            font-size: 22px;
            letter-spacing: 0;
        }

        .empty-state p {
            margin: 0;
            color: var(--text-muted);
            font-size: 15px;
        }

        @media (max-width: 900px) {
            .page {
                width: min(100% - 24px, 720px);
                padding: 32px 0;
            }

            .page-header {
                display: block;
            }

            .task-count {
                display: inline-flex;
                margin-top: 16px;
            }

            .create-panel {
                padding: 18px;
            }

            .filter-panel {
                padding: 18px;
            }

            .dashboard-panel {
                padding: 18px;
            }

            .dashboard-header {
                display: block;
            }

            .dashboard-refresh-time {
                margin-top: 8px;
                text-align: left;
            }

            .dashboard-metrics {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .dashboard-grid {
                grid-template-columns: 1fr;
            }

            .distribution-item {
                grid-template-columns: minmax(56px, 76px) 1fr auto;
            }

            .calendar-panel {
                padding: 18px;
            }

            .calendar-header {
                display: block;
            }

            .calendar-nav {
                margin-top: 12px;
                justify-content: flex-start;
            }

            .calendar-layout {
                grid-template-columns: 1fr;
            }

            .calendar-day {
                min-height: 96px;
                padding: 6px;
            }

            .calendar-summary-list {
                display: none;
            }

            .bulk-panel {
                padding: 18px;
            }

            .bulk-toolbar {
                grid-template-columns: 1fr;
            }

            .import-panel {
                padding: 18px;
            }

            .import-form {
                grid-template-columns: 1fr;
            }

            .backup-panel {
                padding: 18px;
            }

            .backup-restore-container {
                grid-template-columns: 1fr;
            }

            .category-panel {
                padding: 18px;
            }

            .category-create-form,
            .category-row {
                grid-template-columns: 1fr;
            }

            .category-edit-form,
            .category-actions {
                flex-wrap: wrap;
                justify-content: flex-start;
            }

            .filter-form {
                grid-template-columns: 1fr;
            }

            .status-stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .task-panel-header {
                display: none;
            }

            .task-row {
                display: block;
                padding: 18px;
            }

            .task-summary,
            .category-badge,
            .category-empty,
            .status-badge,
            .priority-badge,
            .due-cell,
            .created-time,
            .actions {
                margin-top: 12px;
            }

            .status-cell {
                margin-top: 12px;
            }

            .detail-grid {
                grid-template-columns: 1fr;
            }

            .created-time {
                white-space: normal;
            }
        }
    </style>
</head>
<body>
    <main class="page">
        <header class="page-header">
            <div>
                <h1 class="page-title"><?php echo $isArchiveRequest ? '归档任务管理' : '待办任务管理'; ?></h1>
                <p class="page-subtitle"><?php echo $isArchiveRequest ? '查看已归档任务，支持恢复到普通任务列表。' : '按优先级和创建时间展示任务标题、内容摘要、状态、截止时间、更新时间和操作入口。'; ?></p>
            </div>
            <div class="task-count">
                <?php if ($hasActiveFilters): ?>
                    筛选结果：<?php echo $taskCount; ?> / <?php echo $totalTaskCount; ?> 条
                <?php else: ?>
                    <?php echo $isTrashRequest ? '回收站任务' : ($isArchiveRequest ? '归档任务' : '当前任务'); ?>：<?php echo $totalTaskCount; ?> 条
                <?php endif; ?>
                <div style="margin-top:8px;">
                    <?php if ($isTrashRequest): ?>
                        <a class="action-link" href="index.php">返回普通列表</a>
                        <a class="action-link" href="index.php?action=archive">查看归档列表</a>
                    <?php elseif ($isArchiveRequest): ?>
                        <a class="action-link" href="index.php">返回普通列表</a>
                        <a class="action-link" href="index.php?action=trash">查看回收站</a>
                    <?php else: ?>
                        <a class="action-link" href="index.php?action=archive">查看归档列表</a>
                        <a class="action-link" href="index.php?action=trash">查看回收站</a>
                        <button type="button" class="action-link" onclick="document.getElementById('settings-modal').style.display='block'" style="background:none;border:none;cursor:pointer;color:var(--primary);text-decoration:underline;">系统设置</button>
                    <?php endif; ?>
                </div>
            </div>
        </header>

        <div id="settings-modal" class="modal" style="display:none;">
            <div class="modal-content">
                <div class="modal-header">
                    <h2>系统设置</h2>
                    <button type="button" class="modal-close" onclick="document.getElementById('settings-modal').style.display='none'">&times;</button>
                </div>
                <?php if ($settingsSavedMessage !== ''): ?>
                    <div class="success-message" style="padding:12px;background:#d4edda;color:#155724;border-radius:4px;margin-bottom:16px;"><?php echo escapeHtml($settingsSavedMessage); ?></div>
                <?php endif; ?>
                <?php if ($settingsErrorMessage !== ''): ?>
                    <div class="error-message" style="padding:12px;background:#f8d7da;color:#721c24;border-radius:4px;margin-bottom:16px;"><?php echo escapeHtml($settingsErrorMessage); ?></div>
                <?php endif; ?>
                <form method="post" action="index.php">
                    <input type="hidden" name="form_action" value="settings_save">
                    <div style="margin-bottom:16px;">
                        <label for="default_sort_field" style="display:block;margin-bottom:4px;font-weight:500;">默认排序字段</label>
                        <select id="default_sort_field" name="default_sort_field" class="select" style="width:100%;padding:8px;">
                            <?php
                            $currentSortField = isset($settingsFormValues['default_sort_field']) ? $settingsFormValues['default_sort_field'] : (isset($userSettings[SETTING_KEY_DEFAULT_SORT_FIELD]) ? $userSettings[SETTING_KEY_DEFAULT_SORT_FIELD] : DEFAULT_SORT_FIELD);
                            foreach (ALLOWED_SORT_FIELDS as $field):
                            ?>
                                <option value="<?php echo escapeHtml($field); ?>" <?php echo $currentSortField === $field ? 'selected' : ''; ?>><?php echo escapeHtml($field); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="margin-bottom:16px;">
                        <label for="default_sort_order" style="display:block;margin-bottom:4px;font-weight:500;">默认排序方向</label>
                        <select id="default_sort_order" name="default_sort_order" class="select" style="width:100%;padding:8px;">
                            <?php
                            $currentSortOrder = isset($settingsFormValues['default_sort_order']) ? $settingsFormValues['default_sort_order'] : (isset($userSettings[SETTING_KEY_DEFAULT_SORT_ORDER]) ? $userSettings[SETTING_KEY_DEFAULT_SORT_ORDER] : DEFAULT_SORT_ORDER);
                            ?>
                            <option value="asc" <?php echo $currentSortOrder === 'asc' ? 'selected' : ''; ?>>升序 (ASC)</option>
                            <option value="desc" <?php echo $currentSortOrder === 'desc' ? 'selected' : ''; ?>>降序 (DESC)</option>
                        </select>
                    </div>
                    <div style="margin-bottom:16px;">
                        <label for="default_page_size" style="display:block;margin-bottom:4px;font-weight:500;">每页显示数量</label>
                        <input type="number" id="default_page_size" name="default_page_size" class="input" min="<?php echo MIN_PAGE_SIZE; ?>" max="<?php echo MAX_PAGE_SIZE; ?>" value="<?php echo escapeHtml(isset($settingsFormValues['default_page_size']) ? $settingsFormValues['default_page_size'] : (isset($userSettings[SETTING_KEY_DEFAULT_PAGE_SIZE]) ? $userSettings[SETTING_KEY_DEFAULT_PAGE_SIZE] : (string)DEFAULT_PAGE_SIZE)); ?>" style="width:100%;padding:8px;">
                        <small style="color:var(--text-muted);">有效范围：<?php echo MIN_PAGE_SIZE; ?> - <?php echo MAX_PAGE_SIZE; ?></small>
                    </div>
                    <div style="margin-bottom:16px;">
                        <label for="default_priority" style="display:block;margin-bottom:4px;font-weight:500;">新建任务默认优先级</label>
                        <select id="default_priority" name="default_priority" class="select" style="width:100%;padding:8px;">
                            <?php
                            $currentPriority = isset($settingsFormValues['default_priority']) ? $settingsFormValues['default_priority'] : (isset($userSettings[SETTING_KEY_DEFAULT_PRIORITY]) ? $userSettings[SETTING_KEY_DEFAULT_PRIORITY] : DEFAULT_TASK_PRIORITY);
                            foreach (ALLOWED_PRIORITIES as $priority):
                            ?>
                                <option value="<?php echo escapeHtml($priority); ?>" <?php echo $currentPriority === $priority ? 'selected' : ''; ?>><?php echo escapeHtml($priority); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="margin-bottom:16px;">
                        <label for="reminder_lead_time" style="display:block;margin-bottom:4px;font-weight:500;">提醒提前时间（分钟）</label>
                        <input type="number" id="reminder_lead_time" name="reminder_lead_time" class="input" min="<?php echo MIN_REMINDER_LEAD_TIME; ?>" max="<?php echo MAX_REMINDER_LEAD_TIME; ?>" value="<?php echo escapeHtml(isset($settingsFormValues['reminder_lead_time']) ? $settingsFormValues['reminder_lead_time'] : (isset($userSettings[SETTING_KEY_REMINDER_LEAD_TIME]) ? $userSettings[SETTING_KEY_REMINDER_LEAD_TIME] : (string)DEFAULT_REMINDER_LEAD_TIME)); ?>" style="width:100%;padding:8px;">
                        <small style="color:var(--text-muted);">有效范围：<?php echo MIN_REMINDER_LEAD_TIME; ?> - <?php echo MAX_REMINDER_LEAD_TIME; ?> 分钟（0 表示不提前提醒）</small>
                    </div>
                    <div style="display:flex;gap:12px;justify-content:flex-end;">
                        <button type="button" class="secondary-button" onclick="document.getElementById('settings-modal').style.display='none'">取消</button>
                        <button type="submit" class="primary-button">保存设置</button>
                    </div>
                </form>
            </div>
        </div>

        <section class="dashboard-panel" aria-labelledby="dashboard-title">
            <div class="dashboard-header">
                <div>
                    <h2 class="dashboard-title" id="dashboard-title">任务统计看板</h2>
                    <p class="dashboard-subtitle">统计范围为 SQLite 中未永久删除的任务，刷新页面后按当前数据重新计算。</p>
                </div>
                <div class="dashboard-refresh-time">更新时间：<?php echo escapeHtml(formatDateTime((string) $taskDashboardStats['generated_at'])); ?></div>
            </div>

            <div class="dashboard-metrics" aria-label="核心任务指标">
                <article class="dashboard-metric">
                    <p class="dashboard-metric-label">任务总数</p>
                    <p class="dashboard-metric-value"><?php echo (int) $taskDashboardStats['metrics']['total']; ?></p>
                </article>
                <article class="dashboard-metric">
                    <p class="dashboard-metric-label">已完成</p>
                    <p class="dashboard-metric-value"><?php echo (int) $taskDashboardStats['metrics']['completed']; ?></p>
                </article>
                <article class="dashboard-metric">
                    <p class="dashboard-metric-label">进行中</p>
                    <p class="dashboard-metric-value"><?php echo (int) $taskDashboardStats['metrics']['in_progress']; ?></p>
                </article>
                <article class="dashboard-metric overdue">
                    <p class="dashboard-metric-label">逾期</p>
                    <p class="dashboard-metric-value"><?php echo (int) $taskDashboardStats['metrics']['overdue']; ?></p>
                </article>
                <article class="dashboard-metric">
                    <p class="dashboard-metric-label">归档</p>
                    <p class="dashboard-metric-value"><?php echo (int) $taskDashboardStats['metrics']['archived']; ?></p>
                </article>
            </div>

            <?php if (!$taskDashboardStats['has_tasks']): ?>
                <p class="dashboard-empty">暂无任务数据，所有指标当前为 0。</p>
            <?php else: ?>
                <div class="dashboard-grid">
                    <div class="dashboard-block">
                        <h3>按状态分布</h3>
                        <ul class="distribution-list">
                            <?php foreach (ALLOWED_STATUSES as $statusOption): ?>
                                <?php
                                $statusCount = (int) ($taskDashboardStats['status_counts'][$statusOption] ?? 0);
                                $statusPercent = (int) $taskDashboardStats['metrics']['total'] > 0
                                    ? min(100, max(0, (int) round($statusCount / (int) $taskDashboardStats['metrics']['total'] * 100)))
                                    : 0;
                                ?>
                                <li class="distribution-item">
                                    <span><?php echo escapeHtml($statusOption); ?></span>
                                    <span class="distribution-bar" aria-hidden="true"><span class="distribution-fill" style="width: <?php echo $statusPercent; ?>%;"></span></span>
                                    <span class="distribution-count"><?php echo $statusCount; ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>

                    <div class="dashboard-block">
                        <h3>按优先级分布</h3>
                        <ul class="distribution-list">
                            <?php foreach (ALLOWED_PRIORITIES as $priorityOption): ?>
                                <?php
                                $priorityCount = (int) ($taskDashboardStats['priority_counts'][$priorityOption] ?? 0);
                                $priorityPercent = (int) $taskDashboardStats['metrics']['total'] > 0
                                    ? min(100, max(0, (int) round($priorityCount / (int) $taskDashboardStats['metrics']['total'] * 100)))
                                    : 0;
                                ?>
                                <li class="distribution-item">
                                    <span><?php echo escapeHtml($priorityOption); ?></span>
                                    <span class="distribution-bar" aria-hidden="true"><span class="distribution-fill" style="width: <?php echo $priorityPercent; ?>%;"></span></span>
                                    <span class="distribution-count"><?php echo $priorityCount; ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>

                    <div class="dashboard-block">
                        <h3>近 <?php echo (int) $taskDashboardStats['upcoming_days']; ?> 天待办</h3>
                        <?php if (count($taskDashboardStats['upcoming_tasks']) === 0): ?>
                            <p class="dashboard-empty">近期没有设置截止时间的未完成任务。</p>
                        <?php else: ?>
                            <ul class="upcoming-list">
                                <?php foreach ($taskDashboardStats['upcoming_tasks'] as $upcomingTask): ?>
                                    <?php
                                    $upcomingTaskId = isset($upcomingTask['id']) && is_string($upcomingTask['id']) ? $upcomingTask['id'] : '';
                                    $upcomingTitle = isset($upcomingTask['title']) && is_string($upcomingTask['title']) && trim($upcomingTask['title']) !== '' ? trim($upcomingTask['title']) : '未命名任务';
                                    $upcomingPriority = isset($upcomingTask['priority']) && is_string($upcomingTask['priority']) ? normalizeTaskPriority($upcomingTask['priority']) : DEFAULT_TASK_PRIORITY;
                                    $upcomingDueAt = isset($upcomingTask['due_at']) && is_string($upcomingTask['due_at']) ? $upcomingTask['due_at'] : '';
                                    ?>
                                    <li class="upcoming-item">
                                        <a class="upcoming-title" href="index.php?action=view&amp;id=<?php echo rawurlencode($upcomingTaskId); ?>" title="<?php echo escapeHtml($upcomingTitle); ?>"><?php echo escapeHtml($upcomingTitle); ?></a>
                                        <span class="priority-badge <?php echo escapeHtml(getPriorityBadgeClass($upcomingPriority)); ?>"><?php echo escapeHtml($upcomingPriority); ?></span>
                                        <span class="upcoming-meta">截止：<?php echo escapeHtml(formatDateTime($upcomingDueAt)); ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </section>

        <section class="calendar-panel" id="task-calendar" aria-labelledby="task-calendar-title">
            <?php
            $calendarMonth = isset($taskCalendarView['month']) && is_array($taskCalendarView['month']) ? $taskCalendarView['month'] : normalizeCalendarMonth('');
            $calendarSelectedDate = isset($taskCalendarView['selected_date']) && is_array($taskCalendarView['selected_date']) ? $taskCalendarView['selected_date'] : normalizeCalendarDate('', $calendarMonth);
            $calendarWeeks = isset($taskCalendarView['weeks']) && is_array($taskCalendarView['weeks']) ? $taskCalendarView['weeks'] : [];
            $calendarSelectedEvents = isset($taskCalendarView['selected_events']) && is_array($taskCalendarView['selected_events']) ? $taskCalendarView['selected_events'] : [];
            $calendarWeekdayLabels = ['一', '二', '三', '四', '五', '六', '日'];
            $calendarPrevUrl = 'index.php?calendar_month=' . rawurlencode((string) $calendarMonth['prev_month']) . '&calendar_date=' . rawurlencode((string) $calendarMonth['prev_month'] . '-01') . '#task-calendar';
            $calendarNextUrl = 'index.php?calendar_month=' . rawurlencode((string) $calendarMonth['next_month']) . '&calendar_date=' . rawurlencode((string) $calendarMonth['next_month'] . '-01') . '#task-calendar';
            $calendarTodayMonth = date('Y-m');
            $calendarTodayDate = date('Y-m-d');
            $calendarTodayUrl = 'index.php?calendar_month=' . rawurlencode($calendarTodayMonth) . '&calendar_date=' . rawurlencode($calendarTodayDate) . '#task-calendar';
            ?>
            <div class="calendar-header">
                <div>
                    <h2 class="calendar-title" id="task-calendar-title">任务日历视图</h2>
                    <p class="calendar-subtitle">按截止日期和提醒时间展示任务，当前月份共有 <?php echo (int) $taskCalendarView['total_events']; ?> 个日历事项，覆盖 <?php echo (int) $taskCalendarView['event_days']; ?> 天。</p>
                </div>
                <nav class="calendar-nav" aria-label="日历月份切换">
                    <a class="action-link" href="<?php echo escapeHtml($calendarPrevUrl); ?>">上月</a>
                    <span class="calendar-month-label"><?php echo escapeHtml((string) $calendarMonth['label']); ?></span>
                    <a class="action-link" href="<?php echo escapeHtml($calendarNextUrl); ?>">下月</a>
                    <a class="action-link" href="<?php echo escapeHtml($calendarTodayUrl); ?>">今天</a>
                </nav>
            </div>

            <?php if ((string) ($taskCalendarView['error'] ?? '') !== ''): ?>
                <p class="feedback error" role="alert">日历读取失败，详细异常已写入调试日志。</p>
            <?php endif; ?>

            <div class="calendar-layout">
                <div class="calendar-grid" role="grid" aria-label="<?php echo escapeHtml((string) $calendarMonth['label']); ?>任务日历">
                    <?php foreach ($calendarWeekdayLabels as $weekdayLabel): ?>
                        <div class="calendar-weekday" role="columnheader">周<?php echo escapeHtml($weekdayLabel); ?></div>
                    <?php endforeach; ?>
                    <?php foreach ($calendarWeeks as $calendarWeek): ?>
                        <?php foreach ($calendarWeek as $calendarDay): ?>
                            <?php
                            $dayDate = isset($calendarDay['date']) && is_string($calendarDay['date']) ? $calendarDay['date'] : '';
                            $dayNumber = isset($calendarDay['day_number']) && is_string($calendarDay['day_number']) ? $calendarDay['day_number'] : '';
                            $dayEventCount = isset($calendarDay['event_count']) ? (int) $calendarDay['event_count'] : 0;
                            $dayDueCount = isset($calendarDay['due_count']) ? (int) $calendarDay['due_count'] : 0;
                            $dayRemindCount = isset($calendarDay['remind_count']) ? (int) $calendarDay['remind_count'] : 0;
                            $daySummaries = isset($calendarDay['summaries']) && is_array($calendarDay['summaries']) ? $calendarDay['summaries'] : [];
                            $dayClasses = ['calendar-day'];
                            if (empty($calendarDay['is_current_month'])) {
                                $dayClasses[] = 'empty';
                            }
                            if (!empty($calendarDay['is_today'])) {
                                $dayClasses[] = 'today';
                            }
                            if (!empty($calendarDay['is_selected'])) {
                                $dayClasses[] = 'selected';
                            }
                            $dayUrl = $dayDate !== ''
                                ? 'index.php?calendar_month=' . rawurlencode(substr($dayDate, 0, 7)) . '&calendar_date=' . rawurlencode($dayDate) . '#task-calendar'
                                : '';
                            ?>
                            <div class="<?php echo escapeHtml(implode(' ', $dayClasses)); ?>" role="gridcell" aria-selected="<?php echo !empty($calendarDay['is_selected']) ? 'true' : 'false'; ?>">
                                <?php if ($dayDate !== ''): ?>
                                    <a class="calendar-day-link" href="<?php echo escapeHtml($dayUrl); ?>" aria-label="<?php echo escapeHtml($dayDate . '，' . $dayEventCount . ' 个日历事项'); ?>">
                                        <span class="calendar-day-number"><?php echo escapeHtml($dayNumber); ?></span>
                                        <?php if ($dayEventCount > 0): ?>
                                            <span class="calendar-event-count"><?php echo $dayEventCount; ?> 项</span>
                                            <span class="calendar-event-type-row">
                                                <?php if ($dayDueCount > 0): ?>
                                                    <span class="calendar-type-badge due">截止 <?php echo $dayDueCount; ?></span>
                                                <?php endif; ?>
                                                <?php if ($dayRemindCount > 0): ?>
                                                    <span class="calendar-type-badge remind">提醒 <?php echo $dayRemindCount; ?></span>
                                                <?php endif; ?>
                                            </span>
                                            <ul class="calendar-summary-list" aria-label="<?php echo escapeHtml($dayDate); ?>任务摘要">
                                                <?php foreach ($daySummaries as $summary): ?>
                                                    <?php
                                                    $summaryTitle = isset($summary['title']) && is_string($summary['title']) && trim($summary['title']) !== '' ? trim($summary['title']) : '未命名任务';
                                                    $summaryType = isset($summary['type']) && $summary['type'] === 'remind' ? 'remind' : 'due';
                                                    $summaryTypeLabel = isset($summary['type_label']) && is_string($summary['type_label']) ? $summary['type_label'] : ($summaryType === 'remind' ? '提醒' : '截止');
                                                    ?>
                                                    <li class="calendar-summary-item">
                                                        <span class="calendar-type-badge <?php echo escapeHtml($summaryType); ?>"><?php echo escapeHtml($summaryTypeLabel); ?></span>
                                                        <?php echo escapeHtml($summaryTitle); ?>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>

                <aside class="calendar-detail" aria-labelledby="calendar-detail-title">
                    <div class="calendar-detail-header">
                        <h3 class="calendar-detail-title" id="calendar-detail-title"><?php echo escapeHtml((string) $calendarSelectedDate['label']); ?></h3>
                        <span class="calendar-detail-count"><?php echo count($calendarSelectedEvents); ?> 项</span>
                    </div>
                    <?php if (count($calendarSelectedEvents) === 0): ?>
                        <p class="calendar-empty">当天没有截止任务或提醒事项。</p>
                    <?php else: ?>
                        <ul class="calendar-task-list">
                            <?php foreach ($calendarSelectedEvents as $calendarEvent): ?>
                                <?php
                                $calendarTask = isset($calendarEvent['task']) && is_array($calendarEvent['task']) ? $calendarEvent['task'] : [];
                                $calendarTaskId = isset($calendarTask['id']) && is_string($calendarTask['id']) ? $calendarTask['id'] : '';
                                $calendarTaskTitle = isset($calendarTask['title']) && is_string($calendarTask['title']) && trim($calendarTask['title']) !== '' ? trim($calendarTask['title']) : '未命名任务';
                                $calendarTaskStatus = isset($calendarTask['status']) && is_string($calendarTask['status']) ? normalizeTaskStatus($calendarTask['status']) : '未开始';
                                $calendarTaskPriority = isset($calendarTask['priority']) && is_string($calendarTask['priority']) ? normalizeTaskPriority($calendarTask['priority']) : DEFAULT_TASK_PRIORITY;
                                $calendarTaskCategory = isset($calendarTask['category_name']) && is_string($calendarTask['category_name']) && trim($calendarTask['category_name']) !== '' ? trim($calendarTask['category_name']) : '未分类';
                                $calendarEventType = isset($calendarEvent['type']) && $calendarEvent['type'] === 'remind' ? 'remind' : 'due';
                                $calendarEventTypeLabel = isset($calendarEvent['type_label']) && is_string($calendarEvent['type_label']) ? $calendarEvent['type_label'] : ($calendarEventType === 'remind' ? '提醒' : '截止');
                                $calendarEventAt = isset($calendarEvent['event_at']) && is_string($calendarEvent['event_at']) ? $calendarEvent['event_at'] : '';
                                ?>
                                <li class="calendar-task-item">
                                    <div class="calendar-task-topline">
                                        <span class="calendar-type-badge <?php echo escapeHtml($calendarEventType); ?>"><?php echo escapeHtml($calendarEventTypeLabel); ?></span>
                                        <span class="priority-badge <?php echo escapeHtml(getPriorityBadgeClass($calendarTaskPriority)); ?>"><?php echo escapeHtml($calendarTaskPriority); ?></span>
                                        <span class="status-badge"><?php echo escapeHtml($calendarTaskStatus); ?></span>
                                    </div>
                                    <a class="calendar-task-title" href="index.php?action=view&amp;id=<?php echo rawurlencode($calendarTaskId); ?>"><?php echo escapeHtml($calendarTaskTitle); ?></a>
                                    <div class="calendar-task-meta">
                                        <span><?php echo escapeHtml($calendarEventTypeLabel); ?>：<?php echo escapeHtml(formatDateTime($calendarEventAt)); ?></span>
                                        <span>分类：<?php echo escapeHtml($calendarTaskCategory); ?></span>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </aside>
            </div>
        </section>

        <section class="status-stats" aria-label="任务状态统计">
            <?php foreach (ALLOWED_STATUSES as $statusOption): ?>
                <article class="status-stat">
                    <p class="status-stat-label"><?php echo escapeHtml($statusOption); ?></p>
                    <p class="status-stat-value">
                        <?php echo $hasActiveFilters ? (int) $visibleStatusCounts[$statusOption] : (int) $statusCounts[$statusOption]; ?>
                    </p>
                </article>
            <?php endforeach; ?>
        </section>

        <section class="create-panel" aria-labelledby="create-task-title">
            <h2 id="create-task-title"><?php echo $isEditMode ? '编辑任务' : '新增任务'; ?></h2>

            <?php if ($successMessage !== ''): ?>
                <p class="feedback success" role="status"><?php echo escapeHtml($successMessage); ?></p>
            <?php endif; ?>

            <?php if ($pageErrorMessage !== ''): ?>
                <p class="feedback error" role="alert"><?php echo escapeHtml($pageErrorMessage); ?></p>
            <?php endif; ?>

            <?php if ($saveErrorMessage !== ''): ?>
                <p class="feedback error" role="alert"><?php echo escapeHtml($saveErrorMessage); ?></p>
            <?php endif; ?>

            <form method="post" action="<?php echo $isEditMode ? 'index.php?action=edit&amp;id=' . rawurlencode($editTaskId) : 'index.php'; ?>" data-task-form data-form-action="<?php echo $isEditMode ? 'edit' : 'create'; ?>" novalidate>
                <input type="hidden" name="form_action" value="<?php echo $isEditMode ? 'edit' : 'create'; ?>">
                <?php if ($isEditMode): ?>
                    <input type="hidden" name="task_id" value="<?php echo escapeHtml($editTaskId); ?>">
                <?php endif; ?>
                <div class="form-grid">
                    <div class="form-field">
                        <label for="task-title">任务标题</label>
                        <input
                            class="input<?php echo isset($formErrors['title']) ? ' has-error' : ''; ?>"
                            id="task-title"
                            name="title"
                            type="text"
                            maxlength="<?php echo MAX_TITLE_LENGTH; ?>"
                            value="<?php echo escapeHtml($formValues['title']); ?>"
                            aria-describedby="task-title-help<?php echo isset($formErrors['title']) ? ' task-title-error' : ''; ?>"
                            required
                        >
                        <?php if (isset($formErrors['title'])): ?>
                            <span class="field-error" id="task-title-error"><?php echo escapeHtml($formErrors['title']); ?></span>
                        <?php endif; ?>
                        <span class="field-help" id="task-title-help">最多 <?php echo MAX_TITLE_LENGTH; ?> 个字符。</span>
                    </div>

                    <div class="form-field">
                        <label for="task-status">初始状态</label>
                        <select
                            class="select<?php echo isset($formErrors['status']) ? ' has-error' : ''; ?>"
                            id="task-status"
                            name="status"
                            aria-describedby="<?php echo isset($formErrors['status']) ? 'task-status-error' : 'task-status-help'; ?>"
                        >
                            <?php foreach (ALLOWED_STATUSES as $statusOption): ?>
                                <option value="<?php echo escapeHtml($statusOption); ?>"<?php echo $formValues['status'] === $statusOption ? ' selected' : ''; ?>>
                                    <?php echo escapeHtml($statusOption); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($formErrors['status'])): ?>
                            <span class="field-error" id="task-status-error"><?php echo escapeHtml($formErrors['status']); ?></span>
                        <?php endif; ?>
                        <span class="field-help" id="task-status-help"><?php echo $isEditMode ? '保存后会返回更新后的列表。' : '创建后会直接显示在列表中。'; ?></span>
                    </div>

                    <div class="form-field">
                        <label for="task-priority">任务优先级</label>
                        <select
                            class="select<?php echo isset($formErrors['priority']) ? ' has-error' : ''; ?>"
                            id="task-priority"
                            name="priority"
                            aria-describedby="<?php echo isset($formErrors['priority']) ? 'task-priority-error' : 'task-priority-help'; ?>"
                        >
                            <?php foreach (ALLOWED_PRIORITIES as $priorityOption): ?>
                                <option value="<?php echo escapeHtml($priorityOption); ?>"<?php echo $formValues['priority'] === $priorityOption ? ' selected' : ''; ?>>
                                    <?php echo escapeHtml($priorityOption); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($formErrors['priority'])): ?>
                            <span class="field-error" id="task-priority-error"><?php echo escapeHtml($formErrors['priority']); ?></span>
                        <?php endif; ?>
                        <span class="field-help" id="task-priority-help">未选择时默认使用<?php echo escapeHtml($effectivePriority); ?>优先级。</span>
                    </div>

                    <div class="form-field">
                        <label for="task-category">任务分类</label>
                        <select
                            class="select<?php echo isset($formErrors['category_id']) ? ' has-error' : ''; ?>"
                            id="task-category"
                            name="category_id"
                            aria-describedby="<?php echo isset($formErrors['category_id']) ? 'task-category-error' : 'task-category-help'; ?>"
                        >
                            <option value="">未分类</option>
                            <?php foreach ($categories as $categoryOption): ?>
                                <?php
                                $categoryOptionId = isset($categoryOption['id']) && is_string($categoryOption['id']) ? $categoryOption['id'] : '';
                                $categoryOptionName = isset($categoryOption['name']) && is_string($categoryOption['name']) ? $categoryOption['name'] : '';
                                ?>
                                <option value="<?php echo escapeHtml($categoryOptionId); ?>"<?php echo $formValues['category_id'] === $categoryOptionId ? ' selected' : ''; ?>>
                                    <?php echo escapeHtml($categoryOptionName); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($formErrors['category_id'])): ?>
                            <span class="field-error" id="task-category-error"><?php echo escapeHtml($formErrors['category_id']); ?></span>
                        <?php endif; ?>
                        <span class="field-help" id="task-category-help">可按工作、生活、学习等分类管理。</span>
                    </div>

                    <div class="form-field">
                        <label for="task-due-at">截止日期时间</label>
                        <input
                            class="input<?php echo isset($formErrors['due_at']) ? ' has-error' : ''; ?>"
                            id="task-due-at"
                            name="due_at"
                            type="datetime-local"
                            min="2000-01-01T00:00"
                            max="2100-12-31T23:59"
                            value="<?php echo escapeHtml($formValues['due_at']); ?>"
                            aria-describedby="task-due-at-help<?php echo isset($formErrors['due_at']) ? ' task-due-at-error' : ''; ?>"
                        >
                        <?php if (isset($formErrors['due_at'])): ?>
                            <span class="field-error" id="task-due-at-error"><?php echo escapeHtml($formErrors['due_at']); ?></span>
                        <?php endif; ?>
                        <span class="field-help" id="task-due-at-help">可留空，保存后自动标识逾期、今日到期和未到期。</span>
                    </div>

                    <div class="form-field">
                        <label for="task-remind-at">提醒时间</label>
                        <input
                            class="input<?php echo isset($formErrors['remind_at']) ? ' has-error' : ''; ?>"
                            id="task-remind-at"
                            name="remind_at"
                            type="datetime-local"
                            min="2000-01-01T00:00"
                            max="2100-12-31T23:59"
                            value="<?php echo escapeHtml($formValues['remind_at']); ?>"
                            aria-describedby="task-remind-at-help<?php echo isset($formErrors['remind_at']) ? ' task-remind-at-error' : ''; ?>"
                        >
                        <?php if (isset($formErrors['remind_at'])): ?>
                            <span class="field-error" id="task-remind-at-error"><?php echo escapeHtml($formErrors['remind_at']); ?></span>
                        <?php endif; ?>
                        <span class="field-help" id="task-remind-at-help">提醒时间不能晚于截止日期，且必须晚于当前时间。已完成任务不触发提醒。</span>
                    </div>

                    <div class="form-field">
                        <label for="repeat-rule-type">重复规则</label>
                        <select
                            class="select<?php echo isset($formErrors['repeat_rule']) ? ' has-error' : ''; ?>"
                            id="repeat-rule-type"
                            name="repeat_rule_type"
                            aria-describedby="<?php echo isset($formErrors['repeat_rule']) ? 'repeat-rule-error' : 'repeat-rule-help'; ?>"
                        >
                            <option value="">不重复</option>
                            <?php foreach (ALLOWED_REPEAT_TYPES as $repeatType): ?>
                                <?php $repeatTypeLabel = REPEAT_TYPE_LABELS[$repeatType] ?? $repeatType; ?>
                                <option value="<?php echo escapeHtml($repeatType); ?>"<?php echo ($formValues['repeat_rule_type'] ?? '') === $repeatType ? ' selected' : ''; ?>>
                                    <?php echo escapeHtml('每' . $repeatTypeLabel); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($formErrors['repeat_rule'])): ?>
                            <span class="field-error" id="repeat-rule-error"><?php echo escapeHtml($formErrors['repeat_rule']); ?></span>
                        <?php endif; ?>
                        <span class="field-help" id="repeat-rule-help">设置重复规则需要先指定截止日期。</span>
                    </div>

                    <div class="form-field">
                        <label for="repeat-rule-interval">重复间隔</label>
                        <input
                            class="input<?php echo isset($formErrors['repeat_rule']) ? ' has-error' : ''; ?>"
                            id="repeat-rule-interval"
                            name="repeat_rule_interval"
                            type="number"
                            min="1"
                            max="99"
                            value="<?php echo escapeHtml($formValues['repeat_rule_interval'] ?? '1'); ?>"
                            aria-describedby="repeat-rule-help"
                        >
                        <span class="field-help">每多少天/周/月/年重复一次（1-99）。</span>
                    </div>

                    <div class="form-field">
                        <label for="repeat-rule-end-date">重复结束日期</label>
                        <input
                            class="input<?php echo isset($formErrors['repeat_rule']) ? ' has-error' : ''; ?>"
                            id="repeat-rule-end-date"
                            name="repeat_rule_end_date"
                            type="date"
                            value="<?php echo escapeHtml($formValues['repeat_rule_end_date'] ?? ''); ?>"
                            aria-describedby="repeat-rule-help"
                        >
                        <span class="field-help">可留空，表示无限重复。结束日期不能早于截止日期。</span>
                    </div>

                    <div class="form-field full-width">
                        <label>任务标签</label>
                        <div class="tag-selector" id="task-tags">
                            <?php
                            $currentTagIds = isset($formValues['tag_ids']) && is_array($formValues['tag_ids']) ? $formValues['tag_ids'] : [];
                            ?>
                            <?php if ($tagCount === 0): ?>
                                <span class="tag-empty">暂无标签，请先在下方创建标签。</span>
                            <?php else: ?>
                                <?php foreach ($tags as $tagOption): ?>
                                    <?php
                                    $tagOptionId = isset($tagOption['id']) && is_string($tagOption['id']) ? $tagOption['id'] : '';
                                    $tagOptionName = isset($tagOption['name']) && is_string($tagOption['name']) ? $tagOption['name'] : '';
                                    $tagOptionColor = isset($tagOption['color']) && is_string($tagOption['color']) ? $tagOption['color'] : '#667085';
                                    $isTagSelected = in_array($tagOptionId, $currentTagIds, true);
                                    ?>
                                    <label class="tag-selector-item" style="background-color: <?php echo escapeHtml($tagOptionColor); ?>20; border: 1px solid <?php echo escapeHtml($tagOptionColor); ?>40;">
                                        <input type="checkbox" name="tag_ids[]" value="<?php echo escapeHtml($tagOptionId); ?>"<?php echo $isTagSelected ? ' checked' : ''; ?>>
                                        <?php echo escapeHtml($tagOptionName); ?>
                                    </label>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <span class="field-help" id="task-tags-help">可选择多个标签，支持与关键词、状态筛选组合使用。空标签、重复标签和超长标签会被拦截或自动规范化处理。</span>
                    </div>

                    <div class="form-field full-width">
                        <label for="task-content">任务内容</label>
                        <textarea
                            class="textarea<?php echo isset($formErrors['content']) ? ' has-error' : ''; ?>"
                            id="task-content"
                            name="content"
                            maxlength="<?php echo MAX_CONTENT_LENGTH; ?>"
                            aria-describedby="task-content-help<?php echo isset($formErrors['content']) ? ' task-content-error' : ''; ?>"
                        ><?php echo escapeHtml($formValues['content']); ?></textarea>
                        <?php if (isset($formErrors['content'])): ?>
                            <span class="field-error" id="task-content-error"><?php echo escapeHtml($formErrors['content']); ?></span>
                        <?php endif; ?>
                        <span class="field-help" id="task-content-help">最多 <?php echo MAX_CONTENT_LENGTH; ?> 个字符。</span>
                    </div>
                </div>

                <div class="form-actions">
                    <button class="primary-button" type="submit"><?php echo $isEditMode ? '保存修改' : '创建任务'; ?></button>
                    <?php if ($isEditMode): ?>
                        <a class="secondary-button" href="index.php">取消编辑</a>
                    <?php endif; ?>
                </div>
            </form>
        </section>

        <section class="category-panel" aria-labelledby="category-panel-title">
            <h2 id="category-panel-title">任务分类管理</h2>

            <?php if ($categoryErrorMessage !== ''): ?>
                <p class="feedback error" role="alert"><?php echo escapeHtml($categoryErrorMessage); ?></p>
            <?php endif; ?>

            <form class="category-create-form" method="post" action="index.php" data-category-form data-category-action="create" novalidate>
                <input type="hidden" name="form_action" value="category_create">
                <div class="form-field">
                    <label for="category-name">新分类名称</label>
                    <input
                        class="input<?php echo $categoryFormValues['id'] === '' && isset($categoryFormErrors['category_name']) ? ' has-error' : ''; ?>"
                        id="category-name"
                        name="category_name"
                        type="text"
                        maxlength="<?php echo MAX_CATEGORY_NAME_LENGTH; ?>"
                        value="<?php echo $categoryFormValues['id'] === '' ? escapeHtml($categoryFormValues['name']) : ''; ?>"
                        aria-describedby="category-name-help<?php echo $categoryFormValues['id'] === '' && isset($categoryFormErrors['category_name']) ? ' category-name-error' : ''; ?>"
                        required
                    >
                    <?php if ($categoryFormValues['id'] === '' && isset($categoryFormErrors['category_name'])): ?>
                        <span class="field-error" id="category-name-error"><?php echo escapeHtml($categoryFormErrors['category_name']); ?></span>
                    <?php endif; ?>
                    <span class="field-help" id="category-name-help">最多 <?php echo MAX_CATEGORY_NAME_LENGTH; ?> 个字符，名称不可重复。</span>
                </div>
                <button class="primary-button" type="submit">创建分类</button>
            </form>

            <div class="category-list" aria-label="已有分类">
                <?php if ($categoryCount === 0): ?>
                    <p class="category-empty">暂无分类，创建后可在任务表单中选择。</p>
                <?php else: ?>
                    <?php foreach ($categories as $category): ?>
                        <?php
                        $categoryId = isset($category['id']) && is_string($category['id']) ? $category['id'] : '';
                        $categoryName = isset($category['name']) && is_string($category['name']) ? $category['name'] : '';
                        $categoryActiveCount = isset($category['active_task_count']) ? (int) $category['active_task_count'] : 0;
                        $categoryTotalCount = isset($category['task_count']) ? (int) $category['task_count'] : 0;
                        $isCategoryEditError = $categoryFormValues['id'] === $categoryId && isset($categoryFormErrors['category_name']);
                        ?>
                        <article class="category-row">
                            <p class="category-name"><?php echo escapeHtml($categoryName); ?></p>
                            <span class="category-meta">引用 <?php echo $categoryActiveCount; ?> / <?php echo $categoryTotalCount; ?> 条</span>
                            <form class="category-edit-form" method="post" action="index.php" data-category-form data-category-action="edit" novalidate>
                                <input type="hidden" name="form_action" value="category_edit">
                                <input type="hidden" name="manage_category_id" value="<?php echo escapeHtml($categoryId); ?>">
                                <div class="form-field">
                                    <label for="category-edit-<?php echo escapeHtml($categoryId); ?>">修改名称</label>
                                    <input
                                        class="input<?php echo $isCategoryEditError ? ' has-error' : ''; ?>"
                                        id="category-edit-<?php echo escapeHtml($categoryId); ?>"
                                        name="category_name"
                                        type="text"
                                        maxlength="<?php echo MAX_CATEGORY_NAME_LENGTH; ?>"
                                        value="<?php echo $isCategoryEditError ? escapeHtml($categoryFormValues['name']) : escapeHtml($categoryName); ?>"
                                        aria-describedby="<?php echo $isCategoryEditError ? 'category-edit-error-' . escapeHtml($categoryId) : 'category-edit-help-' . escapeHtml($categoryId); ?>"
                                        required
                                    >
                                    <?php if ($isCategoryEditError): ?>
                                        <span class="field-error" id="category-edit-error-<?php echo escapeHtml($categoryId); ?>"><?php echo escapeHtml($categoryFormErrors['category_name']); ?></span>
                                    <?php endif; ?>
                                    <span class="field-help" id="category-edit-help-<?php echo escapeHtml($categoryId); ?>">空名称、重复名称和超长名称会被拦截。</span>
                                </div>
                                <button class="status-button" type="submit">保存</button>
                            </form>
                            <div class="category-actions">
                                <form class="delete-form" method="post" action="index.php" data-category-delete-form data-category-id="<?php echo escapeHtml($categoryId); ?>" data-category-name="<?php echo escapeHtml($categoryName); ?>">
                                    <input type="hidden" name="form_action" value="category_delete">
                                    <input type="hidden" name="manage_category_id" value="<?php echo escapeHtml($categoryId); ?>">
                                    <button class="delete-button" type="submit"<?php echo $categoryTotalCount > 0 ? ' title="分类已被任务引用，提交后会被保护拦截"' : ''; ?>>删除</button>
                                </form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <section class="tag-panel" aria-labelledby="tag-panel-title">
            <h2 id="tag-panel-title">任务标签管理</h2>

            <?php if ($tagErrorMessage !== ''): ?>
                <p class="feedback error" role="alert"><?php echo escapeHtml($tagErrorMessage); ?></p>
            <?php endif; ?>

            <form class="tag-create-form" method="post" action="index.php" data-tag-form data-tag-action="create" novalidate>
                <input type="hidden" name="form_action" value="tag_create">
                <div class="form-field">
                    <label for="tag-name">新标签名称</label>
                    <input
                        class="input<?php echo $tagFormValues['id'] === '' && isset($tagFormErrors['tag_name']) ? ' has-error' : ''; ?>"
                        id="tag-name"
                        name="tag_name"
                        type="text"
                        maxlength="<?php echo MAX_TAG_NAME_LENGTH; ?>"
                        value="<?php echo $tagFormValues['id'] === '' ? escapeHtml($tagFormValues['name']) : ''; ?>"
                        aria-describedby="tag-name-help<?php echo $tagFormValues['id'] === '' && isset($tagFormErrors['tag_name']) ? ' tag-name-error' : ''; ?>"
                        required
                    >
                    <?php if ($tagFormValues['id'] === '' && isset($tagFormErrors['tag_name'])): ?>
                        <span class="field-error" id="tag-name-error"><?php echo escapeHtml($tagFormErrors['tag_name']); ?></span>
                    <?php endif; ?>
                    <span class="field-help" id="tag-name-help">最多 <?php echo MAX_TAG_NAME_LENGTH; ?> 个字符，名称不可重复。</span>
                </div>
                <div class="form-field">
                    <label for="tag-color">标签颜色</label>
                    <select
                        class="select"
                        id="tag-color"
                        name="tag_color"
                    >
                        <?php foreach (ALLOWED_TAG_COLORS as $colorOption): ?>
                            <option value="<?php echo escapeHtml($colorOption); ?>"<?php echo $tagFormValues['color'] === $colorOption ? ' selected' : ''; ?>>
                                <?php echo escapeHtml($colorOption); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="primary-button" type="submit">创建标签</button>
            </form>

            <div class="tag-list" aria-label="已有标签">
                <?php if ($tagCount === 0): ?>
                    <p class="tag-empty">暂无标签，创建后可在任务中选择。</p>
                <?php else: ?>
                    <?php foreach ($tags as $tag): ?>
                        <?php
                        $tagId = isset($tag['id']) && is_string($tag['id']) ? $tag['id'] : '';
                        $tagName = isset($tag['name']) && is_string($tag['name']) ? $tag['name'] : '';
                        $tagColor = isset($tag['color']) && is_string($tag['color']) ? $tag['color'] : '#667085';
                        $tagTaskCount = isset($tag['task_count']) ? (int) $tag['task_count'] : 0;
                        $isTagEditError = $tagFormValues['id'] === $tagId && isset($tagFormErrors['tag_name']);
                        ?>
                        <article class="tag-row">
                            <p class="tag-name">
                                <span class="tag-color-dot" style="background-color: <?php echo escapeHtml($tagColor); ?>;"></span>
                                <?php echo escapeHtml($tagName); ?>
                            </p>
                            <span class="tag-meta">引用 <?php echo $tagTaskCount; ?> 条</span>
                            <form class="tag-edit-form" method="post" action="index.php" data-tag-form data-tag-action="edit" novalidate>
                                <input type="hidden" name="form_action" value="tag_edit">
                                <input type="hidden" name="manage_tag_id" value="<?php echo escapeHtml($tagId); ?>">
                                <div class="form-field">
                                    <label for="tag-edit-name-<?php echo escapeHtml($tagId); ?>">修改名称</label>
                                    <input
                                        class="input<?php echo $isTagEditError ? ' has-error' : ''; ?>"
                                        id="tag-edit-name-<?php echo escapeHtml($tagId); ?>"
                                        name="tag_name"
                                        type="text"
                                        maxlength="<?php echo MAX_TAG_NAME_LENGTH; ?>"
                                        value="<?php echo $isTagEditError ? escapeHtml($tagFormValues['name']) : escapeHtml($tagName); ?>"
                                        aria-describedby="<?php echo $isTagEditError ? 'tag-edit-error-' . escapeHtml($tagId) : 'tag-edit-help-' . escapeHtml($tagId); ?>"
                                        required
                                    >
                                    <?php if ($isTagEditError): ?>
                                        <span class="field-error" id="tag-edit-error-<?php echo escapeHtml($tagId); ?>"><?php echo escapeHtml($tagFormErrors['tag_name']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="form-field">
                                    <label for="tag-edit-color-<?php echo escapeHtml($tagId); ?>">颜色</label>
                                    <select
                                        class="select"
                                        id="tag-edit-color-<?php echo escapeHtml($tagId); ?>"
                                        name="tag_color"
                                    >
                                        <?php foreach (ALLOWED_TAG_COLORS as $colorOption): ?>
                                            <option value="<?php echo escapeHtml($colorOption); ?>"<?php echo $tagColor === $colorOption ? ' selected' : ''; ?>>
                                                <?php echo escapeHtml($colorOption); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <button class="status-button" type="submit">保存</button>
                            </form>
                            <div class="tag-actions">
                                <form class="delete-form" method="post" action="index.php" data-tag-delete-form data-tag-id="<?php echo escapeHtml($tagId); ?>" data-tag-name="<?php echo escapeHtml($tagName); ?>">
                                    <input type="hidden" name="form_action" value="tag_delete">
                                    <input type="hidden" name="manage_tag_id" value="<?php echo escapeHtml($tagId); ?>">
                                    <button class="delete-button" type="submit">删除</button>
                                </form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <?php if (is_array($viewTask)): ?>
            <?php
            $detailStatus = normalizeTaskStatus((string) $viewTask['status']);
            $detailPriority = normalizeTaskPriority((string) ($viewTask['priority'] ?? DEFAULT_TASK_PRIORITY));
            $detailCategoryName = isset($viewTask['category_name']) && is_string($viewTask['category_name']) && trim($viewTask['category_name']) !== ''
                ? trim($viewTask['category_name'])
                : '未分类';
            $detailArchivedAt = isset($viewTask['archived_at']) && is_string($viewTask['archived_at']) && trim($viewTask['archived_at']) !== ''
                ? formatDateTime($viewTask['archived_at'])
                : '未归档';
            $detailDueState = buildDueAtState($viewTask, 'detail');
            $detailRemindState = buildRemindState($viewTask, 'detail');
            $detailRepeatState = buildRepeatState($viewTask, 'detail');
            $detailTags = isset($viewTask['tags']) && is_array($viewTask['tags']) ? $viewTask['tags'] : [];
            ?>
            <section class="detail-panel" aria-labelledby="task-detail-title">
                <h2 id="task-detail-title">任务详情</h2>
                <div class="detail-grid">
                    <div class="detail-item">
                        <span class="detail-label">标题</span>
                        <span class="detail-value"><?php echo escapeHtml((string) $viewTask['title']); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">状态</span>
                        <span class="status-badge"><?php echo escapeHtml($detailStatus); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">优先级</span>
                        <span class="priority-badge <?php echo escapeHtml(getPriorityBadgeClass($detailPriority)); ?>"><?php echo escapeHtml($detailPriority); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">分类</span>
                        <?php if ($detailCategoryName === '未分类'): ?>
                            <span class="detail-value">未分类</span>
                        <?php else: ?>
                            <span class="category-badge"><?php echo escapeHtml($detailCategoryName); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">截止时间</span>
                        <span class="due-badge <?php echo escapeHtml($detailDueState['class']); ?>"><?php echo escapeHtml($detailDueState['label']); ?></span>
                        <span class="detail-value"><?php echo escapeHtml($detailDueState['description']); ?></span>
                    </div>
                    <?php if ($detailRemindState['datetime'] !== ''): ?>
                    <div class="detail-item">
                        <span class="detail-label">提醒时间</span>
                        <span class="remind-badge <?php echo escapeHtml($detailRemindState['class']); ?>"><?php echo escapeHtml($detailRemindState['label']); ?></span>
                        <span class="detail-value"><?php echo escapeHtml($detailRemindState['description']); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($detailRepeatState['rule'] !== ''): ?>
                    <div class="detail-item">
                        <span class="detail-label">重复规则</span>
                        <span class="repeat-badge <?php echo escapeHtml($detailRepeatState['class']); ?>"><?php echo escapeHtml($detailRepeatState['label']); ?></span>
                        <span class="detail-value"><?php echo escapeHtml($detailRepeatState['description']); ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="detail-item">
                        <span class="detail-label">创建时间</span>
                        <span class="detail-value"><?php echo escapeHtml(formatDateTime((string) $viewTask['created_at'])); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">更新时间</span>
                        <span class="detail-value"><?php echo escapeHtml(formatDateTime((string) $viewTask['updated_at'])); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">归档时间</span>
                        <span class="detail-value"><?php echo escapeHtml($detailArchivedAt); ?></span>
                    </div>
                </div>
                <div class="detail-item">
                    <span class="detail-label">标签</span>
                    <?php if (!empty($detailTags)): ?>
                        <div class="task-tags">
                            <?php foreach ($detailTags as $tag): ?>
                                <?php
                                $tagName = isset($tag['name']) && is_string($tag['name']) ? $tag['name'] : '';
                                $tagColor = isset($tag['color']) && is_string($tag['color']) ? $tag['color'] : '#667085';
                                ?>
                                <span class="tag-badge" style="background-color: <?php echo escapeHtml($tagColor); ?>20; border: 1px solid <?php echo escapeHtml($tagColor); ?>40; color: <?php echo escapeHtml($tagColor); ?>;"><?php echo escapeHtml($tagName); ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <span class="detail-value">暂无标签</span>
                    <?php endif; ?>
                </div>
                <div class="detail-item detail-item-full">
                    <span class="detail-label">完整内容</span>
                    <p class="detail-content"><?php echo escapeHtml((string) $viewTask['content']); ?></p>
                </div>

                <div class="detail-section" id="recurrences">
                    <h3>重复生成记录</h3>
                    <?php if (!empty($viewTaskRecurrences)): ?>
                        <ul class="recurrence-list">
                            <?php foreach ($viewTaskRecurrences as $recurrence): ?>
                                <?php
                                $recurrenceRule = isset($recurrence['repeat_rule']) && is_string($recurrence['repeat_rule']) ? trim($recurrence['repeat_rule']) : '';
                                $recurrenceState = buildRepeatState(['id' => (string) ($recurrence['source_task_id'] ?? ''), 'repeat_rule' => $recurrenceRule], 'detail_recurrence');
                                $sourceTaskId = isset($recurrence['source_task_id']) && is_string($recurrence['source_task_id']) ? $recurrence['source_task_id'] : '';
                                $generatedTaskId = isset($recurrence['generated_task_id']) && is_string($recurrence['generated_task_id']) ? $recurrence['generated_task_id'] : '';
                                $sourceDueAt = normalizeStoredDueAt($recurrence['source_due_at'] ?? '');
                                $generatedDueAt = normalizeStoredDueAt($recurrence['generated_due_at'] ?? '');
                                $createdAt = isset($recurrence['created_at']) && is_string($recurrence['created_at']) ? $recurrence['created_at'] : '';
                                ?>
                                <li class="recurrence-item">
                                    <div>
                                        <span class="repeat-badge <?php echo escapeHtml($recurrenceState['class']); ?>"><?php echo escapeHtml($recurrenceState['label']); ?></span>
                                        <span class="detail-value">从 <?php echo escapeHtml(formatDateTime($sourceDueAt)); ?> 生成到 <?php echo escapeHtml(formatDateTime($generatedDueAt)); ?></span>
                                    </div>
                                    <div class="recurrence-meta">
                                        <span>源任务：<?php echo escapeHtml($sourceTaskId); ?></span>
                                        <span>下一周期：<?php echo escapeHtml($generatedTaskId); ?></span>
                                        <span>记录时间：<?php echo escapeHtml(formatDateTime($createdAt)); ?></span>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="detail-content">暂无重复生成记录。</p>
                    <?php endif; ?>
                </div>

                <div class="detail-section" id="subtasks">
                    <h3>子任务</h3>
                    <?php
                    $totalSubtasks = count($viewTaskSubtasks);
                    $completedSubtasks = 0;
                    foreach ($viewTaskSubtasks as $st) {
                        if (isset($st['is_completed']) && (int) $st['is_completed'] === 1) {
                            $completedSubtasks++;
                        }
                    }
                    $completionRatio = $totalSubtasks > 0 ? round(($completedSubtasks / $totalSubtasks) * 100) : 0;
                    ?>
                    <?php if ($totalSubtasks > 0): ?>
                    <div class="subtask-progress">
                        <div class="subtask-progress-bar">
                            <div class="subtask-progress-fill" style="width: <?php echo $completionRatio; ?>%"></div>
                        </div>
                        <span class="subtask-progress-text"><?php echo $completedSubtasks; ?>/<?php echo $totalSubtasks; ?> (<?php echo $completionRatio; ?>%)</span>
                    </div>
                    <?php endif; ?>

                    <?php if (isset($subtaskErrorMessage) && $subtaskErrorMessage !== ''): ?>
                        <p class="feedback error" role="alert"><?php echo escapeHtml($subtaskErrorMessage); ?></p>
                    <?php endif; ?>

                    <div class="subtask-add-form">
                        <form method="post" action="index.php" class="subtask-form-inline">
                            <input type="hidden" name="form_action" value="subtask_create">
                            <input type="hidden" name="subtask_task_id" value="<?php echo escapeHtml($requestedTaskId); ?>">
                            <input type="text" name="subtask_title" class="input subtask-input" placeholder="添加子任务..." maxlength="<?php echo MAX_SUBTASK_TITLE_LENGTH; ?>" required>
                            <button type="submit" class="primary-button subtask-add-btn">添加</button>
                        </form>
                    </div>

                    <?php if (!empty($viewTaskSubtasks)): ?>
                    <ul class="subtask-list">
                        <?php foreach ($viewTaskSubtasks as $subtask): ?>
                            <?php
                            $subtaskId = isset($subtask['id']) && is_string($subtask['id']) ? $subtask['id'] : '';
                            $subtaskTitle = isset($subtask['title']) && is_string($subtask['title']) ? $subtask['title'] : '';
                            $subtaskCompleted = isset($subtask['is_completed']) && (int) $subtask['is_completed'] === 1;
                            $subtaskCreatedAt = isset($subtask['created_at']) && is_string($subtask['created_at']) ? $subtask['created_at'] : '';
                            $subtaskUpdatedAt = isset($subtask['updated_at']) && is_string($subtask['updated_at']) ? $subtask['updated_at'] : '';
                            ?>
                            <li class="subtask-item<?php echo $subtaskCompleted ? ' completed' : ''; ?>" data-subtask-id="<?php echo escapeHtml($subtaskId); ?>">
                                <form method="post" action="index.php" class="subtask-form-inline subtask-toggle-form">
                                    <input type="hidden" name="form_action" value="subtask_toggle">
                                    <input type="hidden" name="subtask_id" value="<?php echo escapeHtml($subtaskId); ?>">
                                    <input type="hidden" name="subtask_task_id" value="<?php echo escapeHtml($requestedTaskId); ?>">
                                    <button type="submit" class="subtask-checkbox-btn" title="<?php echo $subtaskCompleted ? '标记为未完成' : '标记为已完成'; ?>">
                                        <?php echo $subtaskCompleted ? '☑' : '☐'; ?>
                                    </button>
                                </form>
                                <form method="post" action="index.php" class="subtask-form-inline subtask-edit-form" style="display: none;">
                                    <input type="hidden" name="form_action" value="subtask_edit">
                                    <input type="hidden" name="subtask_id" value="<?php echo escapeHtml($subtaskId); ?>">
                                    <input type="hidden" name="subtask_task_id" value="<?php echo escapeHtml($requestedTaskId); ?>">
                                    <input type="text" name="subtask_title" class="input subtask-edit-input" value="<?php echo escapeHtml($subtaskTitle); ?>" maxlength="<?php echo MAX_SUBTASK_TITLE_LENGTH; ?>" required>
                                    <button type="submit" class="primary-button subtask-save-btn">保存</button>
                                    <button type="button" class="secondary-button subtask-cancel-btn">取消</button>
                                </form>
                                <span class="subtask-title<?php echo $subtaskCompleted ? ' completed' : ''; ?>"><?php echo escapeHtml($subtaskTitle); ?></span>
                                <span class="subtask-time"><?php echo escapeHtml(formatDateTime($subtaskUpdatedAt ?: $subtaskCreatedAt)); ?></span>
                                <div class="subtask-actions">
                                    <button type="button" class="subtask-action-btn subtask-edit-btn" title="编辑">✏️</button>
                                    <form method="post" action="index.php" class="subtask-form-inline subtask-delete-form">
                                        <input type="hidden" name="form_action" value="subtask_delete">
                                        <input type="hidden" name="subtask_id" value="<?php echo escapeHtml($subtaskId); ?>">
                                        <input type="hidden" name="subtask_task_id" value="<?php echo escapeHtml($requestedTaskId); ?>">
                                        <button type="submit" class="subtask-action-btn subtask-delete-btn" title="删除" onclick="return confirm('确定要删除这个子任务吗？');">🗑️</button>
                                    </form>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php else: ?>
                    <p class="detail-empty">暂无子任务，添加一个吧</p>
                    <?php endif; ?>
                </div>

                <?php if (!empty($viewTaskComments)): ?>
                <div class="detail-section" id="comments">
                    <h3>评论 (<?php echo count($viewTaskComments); ?>)</h3>
                    <?php if (isset($commentErrorMessage) && $commentErrorMessage !== ''): ?>
                        <p class="feedback error" role="alert"><?php echo escapeHtml($commentErrorMessage); ?></p>
                    <?php endif; ?>
                    <ul class="comment-list">
                        <?php foreach ($viewTaskComments as $comment): ?>
                            <?php
                            $commentContent = isset($comment['content']) && is_string($comment['content']) ? $comment['content'] : '';
                            $commentTime = isset($comment['created_at']) && is_string($comment['created_at']) ? $comment['created_at'] : '';
                            ?>
                            <li class="comment-item">
                                <div class="comment-content"><?php echo escapeHtml($commentContent); ?></div>
                                <div class="comment-time"><?php echo escapeHtml(formatDateTime($commentTime)); ?></div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="comment-add-form">
                        <form method="post" action="index.php" class="comment-form">
                            <input type="hidden" name="form_action" value="comment_create">
                            <input type="hidden" name="comment_task_id" value="<?php echo escapeHtml($requestedTaskId); ?>">
                            <textarea name="comment_content" class="input comment-textarea" placeholder="添加评论..." maxlength="<?php echo MAX_COMMENT_CONTENT_LENGTH; ?>" rows="3" required></textarea>
                            <button type="submit" class="primary-button comment-add-btn">发表评论</button>
                        </form>
                    </div>
                </div>
                <?php else: ?>
                <div class="detail-section" id="comments">
                    <h3>评论</h3>
                    <?php if (isset($commentErrorMessage) && $commentErrorMessage !== ''): ?>
                        <p class="feedback error" role="alert"><?php echo escapeHtml($commentErrorMessage); ?></p>
                    <?php endif; ?>
                    <p class="detail-empty">暂无评论</p>
                    <div class="comment-add-form">
                        <form method="post" action="index.php" class="comment-form">
                            <input type="hidden" name="form_action" value="comment_create">
                            <input type="hidden" name="comment_task_id" value="<?php echo escapeHtml($requestedTaskId); ?>">
                            <textarea name="comment_content" class="input comment-textarea" placeholder="添加评论..." maxlength="<?php echo MAX_COMMENT_CONTENT_LENGTH; ?>" rows="3" required></textarea>
                            <button type="submit" class="primary-button comment-add-btn">发表评论</button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($viewTaskAttachments)): ?>
                <div class="detail-section" id="attachments">
                    <h3>附件 (<?php echo count($viewTaskAttachments); ?>)</h3>
                    <?php if (isset($attachmentErrorMessage) && $attachmentErrorMessage !== ''): ?>
                        <p class="feedback error" role="alert"><?php echo escapeHtml($attachmentErrorMessage); ?></p>
                    <?php endif; ?>
                    <ul class="attachment-list">
                        <?php foreach ($viewTaskAttachments as $attachment): ?>
                            <?php
                            $attachmentId = isset($attachment['id']) && is_string($attachment['id']) ? $attachment['id'] : '';
                            $attachmentFileName = isset($attachment['file_name']) && is_string($attachment['file_name']) ? $attachment['file_name'] : '';
                            $attachmentFileSize = isset($attachment['file_size']) && is_int($attachment['file_size']) ? $attachment['file_size'] : 0;
                            $attachmentMimeType = isset($attachment['mime_type']) && is_string($attachment['mime_type']) ? $attachment['mime_type'] : '';
                            $attachmentCreatedAt = isset($attachment['created_at']) && is_string($attachment['created_at']) ? $attachment['created_at'] : '';
                            $formattedSize = $attachmentFileSize >= 1024 * 1024
                                ? round($attachmentFileSize / 1024 / 1024, 2) . ' MB'
                                : ($attachmentFileSize >= 1024
                                    ? round($attachmentFileSize / 1024, 2) . ' KB'
                                    : $attachmentFileSize . ' B');
                            ?>
                            <li class="attachment-item">
                                <div class="attachment-info">
                                    <span class="attachment-icon">📎</span>
                                    <span class="attachment-name" title="<?php echo escapeHtml($attachmentFileName); ?>"><?php echo escapeHtml($attachmentFileName); ?></span>
                                    <span class="attachment-meta">(<?php echo escapeHtml($formattedSize); ?>, <?php echo escapeHtml($attachmentMimeType); ?>)</span>
                                    <span class="attachment-time"><?php echo escapeHtml(formatDateTime($attachmentCreatedAt)); ?></span>
                                </div>
                                <form method="post" action="index.php" class="attachment-delete-form" onsubmit="return confirm('确定要删除这个附件吗？');">
                                    <input type="hidden" name="form_action" value="attachment_delete">
                                    <input type="hidden" name="attachment_id" value="<?php echo escapeHtml($attachmentId); ?>">
                                    <input type="hidden" name="attachment_task_id" value="<?php echo escapeHtml($requestedTaskId); ?>">
                                    <button type="submit" class="danger-button attachment-delete-btn">删除</button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php else: ?>
                <div class="detail-section" id="attachments">
                    <h3>附件</h3>
                    <?php if (isset($attachmentErrorMessage) && $attachmentErrorMessage !== ''): ?>
                        <p class="feedback error" role="alert"><?php echo escapeHtml($attachmentErrorMessage); ?></p>
                    <?php endif; ?>
                    <p class="detail-empty">暂无附件</p>
                </div>
                <?php endif; ?>

                <div class="detail-section">
                    <h4>添加附件</h4>
                    <form method="post" action="index.php" enctype="multipart/form-data" class="attachment-add-form">
                        <input type="hidden" name="form_action" value="attachment_add">
                        <input type="hidden" name="attachment_task_id" value="<?php echo escapeHtml($requestedTaskId); ?>">
                        <div class="form-field">
                            <label for="attachment-file">选择文件</label>
                            <input type="file" id="attachment-file" name="attachment_file" class="input" accept="<?php echo escapeHtml(implode(',', ALLOWED_ATTACHMENT_MIME_TYPES)); ?>">
                            <small class="form-help">最大文件大小：<?php echo MAX_ATTACHMENT_FILE_SIZE / 1024 / 1024; ?> MB</small>
                        </div>
                        <button type="submit" class="primary-button">上传附件</button>
                    </form>
                </div>

                <?php if (!empty($viewTaskHistory)): ?>
                <div class="detail-section">
                    <h3>历史记录 (<?php echo count($viewTaskHistory); ?>)</h3>
                    <ul class="history-list">
                        <?php foreach ($viewTaskHistory as $entry): ?>
                            <?php
                            $historyOp = isset($entry['operation']) && is_string($entry['operation']) ? $entry['operation'] : '';
                            $historyStatus = isset($entry['status']) && is_string($entry['status']) ? $entry['status'] : '';
                            $historyTime = isset($entry['created_at']) && is_string($entry['created_at']) ? $entry['created_at'] : '';
                            $historyChanges = isset($entry['field_changes']) && is_array($entry['field_changes']) ? $entry['field_changes'] : [];
                            $historyResult = isset($entry['result']) && is_array($entry['result']) ? $entry['result'] : [];
                            ?>
                            <li class="history-item">
                                <span class="history-status <?php echo escapeHtml($historyStatus); ?>"><?php echo escapeHtml($historyStatus); ?></span>
                                <span class="history-operation"><?php echo escapeHtml(getTaskHistoryOperationLabel($historyOp)); ?></span>
                                <span class="history-time"><?php echo escapeHtml(formatDateTime($historyTime)); ?></span>
                                <?php if (!empty($historyChanges)): ?>
                                    <ul class="history-changes">
                                        <?php foreach ($historyChanges as $fieldName => $change): ?>
                                            <?php
                                            $beforeValue = is_array($change) && array_key_exists('before', $change) ? stringifyHistoryValue($change['before']) : '空';
                                            $afterValue = is_array($change) && array_key_exists('after', $change) ? stringifyHistoryValue($change['after']) : '空';
                                            ?>
                                            <li class="history-change">
                                                <strong><?php echo escapeHtml(getTaskHistoryFieldLabel((string) $fieldName)); ?></strong>：
                                                <?php echo escapeHtml($beforeValue); ?> → <?php echo escapeHtml($afterValue); ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php elseif (!empty($historyResult)): ?>
                                    <ul class="history-changes">
                                        <li class="history-change">结果：<?php echo escapeHtml(stringifyHistoryValue($historyResult)); ?></li>
                                    </ul>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php else: ?>
                <div class="detail-section">
                    <h3>历史记录</h3>
                    <p class="detail-empty">暂无历史记录</p>
                </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <section class="filter-panel" aria-labelledby="task-filter-title">
            <h2 id="task-filter-title">搜索筛选与排序</h2>

            <?php foreach ($filterErrors as $filterError): ?>
                <p class="feedback error" role="alert"><?php echo escapeHtml($filterError); ?></p>
            <?php endforeach; ?>

            <?php foreach ($sortErrors as $sortError): ?>
                <p class="feedback error" role="alert"><?php echo escapeHtml($sortError); ?></p>
            <?php endforeach; ?>

            <form class="filter-form" method="get" action="index.php" data-filter-form novalidate>
                <?php if ($isTrashRequest): ?>
                    <input type="hidden" name="action" value="trash">
                <?php elseif ($isArchiveRequest): ?>
                    <input type="hidden" name="action" value="archive">
                <?php endif; ?>
                <input type="hidden" name="filter_action" value="apply">
                <div class="form-field">
                    <label for="task-keyword">关键词</label>
                    <input
                        class="input<?php echo isset($filterErrors['keyword']) ? ' has-error' : ''; ?>"
                        id="task-keyword"
                        name="keyword"
                        type="search"
                        maxlength="<?php echo MAX_SEARCH_KEYWORD_LENGTH; ?>"
                        value="<?php echo escapeHtml($searchKeyword); ?>"
                        placeholder="搜索标题或内容"
                        aria-describedby="task-keyword-help"
                    >
                    <span class="field-help" id="task-keyword-help">支持标题和内容匹配，最多 <?php echo MAX_SEARCH_KEYWORD_LENGTH; ?> 个字符。</span>
                </div>

                <div class="form-field">
                    <label for="filter-status">任务状态</label>
                    <select class="select<?php echo isset($filterErrors['status']) ? ' has-error' : ''; ?>" id="filter-status" name="status">
                        <option value="">全部状态</option>
                        <?php foreach (ALLOWED_STATUSES as $statusOption): ?>
                            <option value="<?php echo escapeHtml($statusOption); ?>"<?php echo $statusFilter === $statusOption ? ' selected' : ''; ?>>
                                <?php echo escapeHtml($statusOption); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-field">
                    <label for="filter-priority">任务优先级</label>
                    <select class="select<?php echo isset($filterErrors['priority']) ? ' has-error' : ''; ?>" id="filter-priority" name="priority">
                        <option value="">全部优先级</option>
                        <?php foreach (ALLOWED_PRIORITIES as $priorityOption): ?>
                            <option value="<?php echo escapeHtml($priorityOption); ?>"<?php echo $priorityFilter === $priorityOption ? ' selected' : ''; ?>>
                                <?php echo escapeHtml($priorityOption); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-field">
                    <label for="filter-tag">任务标签</label>
                    <select class="select<?php echo isset($filterErrors['tag_id']) ? ' has-error' : ''; ?>" id="filter-tag" name="tag_id">
                        <option value="">全部标签</option>
                        <?php foreach ($tags as $tagOption): ?>
                            <?php
                            $tagOptionId = isset($tagOption['id']) && is_string($tagOption['id']) ? $tagOption['id'] : '';
                            $tagOptionName = isset($tagOption['name']) && is_string($tagOption['name']) ? $tagOption['name'] : '';
                            $tagOptionColor = isset($tagOption['color']) && is_string($tagOption['color']) ? $tagOption['color'] : '#667085';
                            ?>
                            <option value="<?php echo escapeHtml($tagOptionId); ?>"<?php echo $tagFilter === $tagOptionId ? ' selected' : ''; ?>>
                                <?php echo escapeHtml($tagOptionName); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-field">
                    <label for="filter-sort-by">排序字段</label>
                    <select class="select" id="filter-sort-by" name="sort_by">
                        <option value="created_at"<?php echo $sortBy === 'created_at' ? ' selected' : ''; ?>>按创建时间</option>
                        <option value="updated_at"<?php echo $sortBy === 'updated_at' ? ' selected' : ''; ?>>按更新时间</option>
                        <option value="due_at"<?php echo $sortBy === 'due_at' ? ' selected' : ''; ?>>按截止时间</option>
                        <option value="priority"<?php echo $sortBy === 'priority' ? ' selected' : ''; ?>>按优先级</option>
                        <option value="status"<?php echo $sortBy === 'status' ? ' selected' : ''; ?>>按状态</option>
                        <option value="title"<?php echo $sortBy === 'title' ? ' selected' : ''; ?>>按标题</option>
                    </select>
                </div>

                <div class="form-field">
                    <label for="filter-sort-order">排序方向</label>
                    <select class="select" id="filter-sort-order" name="sort_order">
                        <option value="desc"<?php echo $sortOrder === 'desc' ? ' selected' : ''; ?>>降序（由高到低）</option>
                        <option value="asc"<?php echo $sortOrder === 'asc' ? ' selected' : ''; ?>>升序（由低到高）</option>
                    </select>
                </div>

                <div class="form-field">
                    <label for="filter-page-size">每页条数</label>
                    <select class="select pagination-size-select" id="filter-page-size" name="page_size">
                        <option value="10"<?php echo $pageSize === 10 ? ' selected' : ''; ?>>10 条/页</option>
                        <option value="20"<?php echo $pageSize === 20 ? ' selected' : ''; ?>>20 条/页</option>
                        <option value="50"<?php echo $pageSize === 50 ? ' selected' : ''; ?>>50 条/页</option>
                        <option value="100"<?php echo $pageSize === 100 ? ' selected' : ''; ?>>100 条/页</option>
                    </select>
                </div>

                <input type="hidden" name="page" id="pagination-page-input-hidden" value="<?php echo (int) $page; ?>">
                <button class="primary-button" type="submit">搜索</button>
                <a class="secondary-button" href="index.php?<?php echo $isTrashRequest ? 'action=trash&amp;' : ($isArchiveRequest ? 'action=archive&amp;' : ''); ?>filter_action=clear&amp;keyword=&amp;status=&amp;priority=&amp;tag_id=&amp;sort_by=<?php echo DEFAULT_SORT_FIELD; ?>&amp;sort_order=<?php echo DEFAULT_SORT_ORDER; ?>&amp;page=1&amp;page_size=<?php echo DEFAULT_PAGE_SIZE; ?>" data-filter-clear>清空筛选</a>
            </form>

            <p class="filter-summary" role="status">
                <?php if ($hasActiveFilters): ?>
                    当前显示 <?php echo $taskCount; ?> 条匹配任务，完整列表共 <?php echo $totalTaskCount; ?> 条。
                <?php else: ?>
                    当前显示完整<?php echo $isTrashRequest ? '回收站' : ($isArchiveRequest ? '归档' : '任务'); ?>列表，共 <?php echo $totalTaskCount; ?> 条。
                <?php endif; ?>
            </p>
        </section>

        <?php if (!$isTrashRequest): ?>
            <section class="bulk-panel" aria-labelledby="bulk-task-title">
                <h2 id="bulk-task-title">任务批量操作</h2>
                <form class="bulk-toolbar" method="post" action="index.php" data-bulk-form novalidate>
                    <input type="hidden" name="form_action" value="bulk_action">
                    <div class="bulk-selection-count" data-bulk-selected-count role="status">已选择 0 条</div>
                    <div class="form-field">
                        <label for="bulk-category-id">目标分类</label>
                        <select class="select" id="bulk-category-id" name="bulk_category_id">
                            <option value="">设为未分类</option>
                            <?php foreach ($categories as $categoryOption): ?>
                                <?php
                                $bulkCategoryId = isset($categoryOption['id']) && is_string($categoryOption['id']) ? $categoryOption['id'] : '';
                                $bulkCategoryName = isset($categoryOption['name']) && is_string($categoryOption['name']) ? $categoryOption['name'] : '';
                                ?>
                                <option value="<?php echo escapeHtml($bulkCategoryId); ?>"><?php echo escapeHtml($bulkCategoryName); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-field">
                        <label for="bulk-priority">目标优先级</label>
                        <select class="select" id="bulk-priority" name="bulk_priority">
                            <?php foreach (ALLOWED_PRIORITIES as $priorityOption): ?>
                                <option value="<?php echo escapeHtml($priorityOption); ?>"<?php echo $priorityOption === DEFAULT_TASK_PRIORITY ? ' selected' : ''; ?>>
                                    <?php echo escapeHtml($priorityOption); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button class="bulk-button" type="submit" name="bulk_action" value="complete" data-bulk-submit disabled>批量完成</button>
                    <button class="bulk-button" type="submit" name="bulk_action" value="archive" data-bulk-submit disabled>批量归档</button>
                    <button class="bulk-button" type="submit" name="bulk_action" value="category" data-bulk-submit disabled>修改分类</button>
                    <button class="bulk-button" type="submit" name="bulk_action" value="priority" data-bulk-submit disabled>修改优先级</button>
                    <button class="bulk-button danger" type="submit" name="bulk_action" value="delete" data-bulk-submit disabled>批量删除</button>
                    <div class="bulk-hidden-selected" data-bulk-hidden-selected aria-hidden="true"></div>
                </form>
            </section>
        <?php endif; ?>

        <section class="import-panel" aria-labelledby="import-panel-title">
            <h2 id="import-panel-title">CSV 任务导入</h2>
            <?php if (isset($importErrorMessage) && $importErrorMessage !== ''): ?>
                <p class="feedback error" role="alert"><?php echo escapeHtml($importErrorMessage); ?></p>
            <?php endif; ?>
            <form class="import-form" method="post" action="index.php" enctype="multipart/form-data" novalidate>
                <input type="hidden" name="form_action" value="csv_import">
                <div class="form-field">
                    <label for="csv-file">选择 CSV 文件</label>
                    <input
                        class="input"
                        id="csv-file"
                        name="csv_file"
                        type="file"
                        accept=".csv,text/csv"
                        required
                    >
                    <span class="field-help">支持 CSV 格式文件，最大 <?php echo CSV_IMPORT_MAX_FILE_SIZE / 1024 / 1024; ?> MB，最多 <?php echo CSV_IMPORT_MAX_ROWS; ?> 行数据。</span>
                </div>
                <div class="form-field">
                    <label>CSV 格式说明</label>
                    <div class="import-format-help">
                        <p><strong>必需列：</strong>title（任务标题）</p>
                        <p><strong>可选列：</strong>content（内容）、status（状态）、priority（优先级）、category（分类）、tags（标签，多个用逗号分隔）、due_at（截止日期，格式如 2026-06-01T12:00）</p>
                        <p><strong>状态有效值：</strong><?php echo implode('、', ALLOWED_STATUSES); ?></p>
                        <p><strong>优先级有效值：</strong><?php echo implode('、', ALLOWED_PRIORITIES); ?></p>
                    </div>
                </div>
                <button class="primary-button" type="submit">导入任务</button>
            </form>
        </section>

        <section class="export-panel" aria-labelledby="export-panel-title">
            <h2 id="export-panel-title">CSV 任务导出</h2>
            <form class="export-form" method="post" action="index.php" novalidate>
                <input type="hidden" name="form_action" value="csv_export">
                <input type="hidden" name="export_keyword" value="<?php echo escapeHtml($searchKeyword); ?>">
                <input type="hidden" name="export_status" value="<?php echo escapeHtml($statusFilter); ?>">
                <input type="hidden" name="export_priority" value="<?php echo escapeHtml($priorityFilter); ?>">
                <input type="hidden" name="export_tag_id" value="<?php echo escapeHtml($tagFilter); ?>">
                <input type="hidden" name="export_visibility" value="<?php echo escapeHtml($taskListVisibility); ?>">
                <div class="form-field">
                    <label>导出范围</label>
                    <div class="export-scope-info">
                        <p>当前筛选条件：<?php echo $totalTaskCount; ?> 条任务符合条件</p>
                        <?php if ($searchKeyword !== ''): ?>
                            <p>关键词：<?php echo escapeHtml($searchKeyword); ?></p>
                        <?php endif; ?>
                        <?php if ($statusFilter !== ''): ?>
                            <p>状态：<?php echo escapeHtml($statusFilter); ?></p>
                        <?php endif; ?>
                        <?php if ($priorityFilter !== ''): ?>
                            <p>优先级：<?php echo escapeHtml($priorityFilter); ?></p>
                        <?php endif; ?>
                        <?php if ($tagFilter !== ''): ?>
                            <p>标签：<?php
                                $tagFilterName = '';
                                foreach ($tags as $t) {
                                    if (isset($t['id']) && $t['id'] === $tagFilter) {
                                        $tagFilterName = isset($t['name']) ? $t['name'] : '';
                                        break;
                                    }
                                }
                                echo escapeHtml($tagFilterName);
                            ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="form-field">
                    <label>导出字段</label>
                    <div class="export-fields-info">
                        <p>title（标题）、content（内容）、status（状态）、priority（优先级）、category（分类）、tags（标签）、due_at（截止日期）、created_at（创建时间）、updated_at（更新时间）</p>
                    </div>
                </div>
                <button class="primary-button" type="submit">导出 CSV 文件</button>
            </form>
        </section>

        <section class="backup-panel" aria-labelledby="backup-panel-title">
            <h2 id="backup-panel-title">数据备份与恢复</h2>
            <?php if (isset($restoreErrorMessage) && $restoreErrorMessage !== ''): ?>
                <p class="feedback error" role="alert"><?php echo escapeHtml($restoreErrorMessage); ?></p>
            <?php endif; ?>
            <div class="backup-restore-container">
                <div class="backup-section">
                    <h3>创建备份</h3>
                    <p class="backup-description">生成当前 SQLite 数据库的完整备份文件，包含所有任务、分类、标签、子任务、评论、附件等业务数据。</p>
                    <form class="backup-form" method="post" action="index.php" novalidate>
                        <input type="hidden" name="form_action" value="database_backup">
                        <button class="primary-button" type="submit">下载数据库备份</button>
                    </form>
                </div>
                <div class="restore-section">
                    <h3>从备份恢复</h3>
                    <p class="restore-description">从备份文件恢复数据。恢复前会自动创建当前数据的预备份，以便在恢复失败时进行回滚。</p>
                    <form class="restore-form" method="post" action="index.php" enctype="multipart/form-data" novalidate>
                        <input type="hidden" name="form_action" value="database_restore">
                        <div class="form-field">
                            <label for="restore-file">选择备份文件</label>
                            <input
                                class="input"
                                id="restore-file"
                                name="restore_file"
                                type="file"
                                accept=".sqlite,.db"
                                required
                            >
                            <span class="field-help">支持 .sqlite 和 .db 格式的备份文件，最大 <?php echo MAX_RESTORE_FILE_SIZE / 1024 / 1024; ?> MB。</span>
                        </div>
                        <button class="primary-button" type="submit">恢复数据库</button>
                    </form>
                </div>
            </div>
        </section>

        <section class="task-panel" aria-labelledby="task-list-title">
            <div class="task-panel-header<?php echo $isTrashRequest ? ' trash-panel-header' : ''; ?>">
                <?php if (!$isTrashRequest): ?>
                    <div class="bulk-select-cell">
                        <input class="bulk-checkbox" type="checkbox" id="bulk-select-all" data-bulk-select-all aria-label="选择当前页全部任务">
                    </div>
                <?php endif; ?>
                <div>任务标题</div>
                <div>内容摘要</div>
                <div>分类</div>
                <div>优先级</div>
                <div>状态</div>
                <div>截止时间</div>
                <div>更新时间</div>
                <div>操作</div>
            </div>

            <h2 id="task-list-title" style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden;">任务列表</h2>

            <?php if ($taskCount === 0): ?>
                <div class="empty-state">
                    <?php if ($hasActiveFilters && $totalTaskCount > 0): ?>
                        <h2>未找到匹配任务</h2>
                        <p>当前关键词或状态没有匹配结果，清空筛选后可查看完整任务列表。</p>
                    <?php else: ?>
                        <h2><?php echo $isTrashRequest ? '回收站为空' : '暂无待办任务'; ?></h2>
                        <p><?php echo $isTrashRequest ? '当前没有已删除任务。' : '当前没有任务数据，后续可通过新增入口创建第一条任务。'; ?></p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <?php foreach ($visibleTasks as $task): ?>
                    <?php
                    $taskId = rawurlencode($task['id']);
                    $safeTitle = escapeHtml($task['title']);
                    $safeSummary = escapeHtml(createSummary($task['content']));
                    $normalizedStatus = normalizeTaskStatus((string) $task['status']);
                    $normalizedPriority = normalizeTaskPriority((string) ($task['priority'] ?? DEFAULT_TASK_PRIORITY));
                    $categoryName = isset($task['category_name']) && is_string($task['category_name']) ? trim($task['category_name']) : '';
                    $safeStatus = escapeHtml($normalizedStatus);
                    $safePriority = escapeHtml($normalizedPriority);
                    $safeCategoryName = escapeHtml($categoryName);
                    $priorityClass = escapeHtml(getPriorityBadgeClass($normalizedPriority));
                    $safeCreatedAt = escapeHtml(formatDateTime($task['created_at']));
                    $safeUpdatedAt = escapeHtml(formatDateTime($task['updated_at']));
                    $dueState = buildDueAtState($task, 'list');
                    $safeDueLabel = escapeHtml($dueState['label']);
                    $safeDueClass = escapeHtml($dueState['class']);
                    $safeDueDescription = escapeHtml($dueState['description']);
                    $safeDueDateTime = escapeHtml((string) $dueState['datetime']);
                    $remindState = buildRemindState($task, 'list');
                    $safeRemindLabel = escapeHtml($remindState['label']);
                    $safeRemindClass = escapeHtml($remindState['class']);
                    $safeRemindDescription = escapeHtml($remindState['description']);
                    $safeRemindDateTime = escapeHtml((string) $remindState['datetime']);
                    ?>
                    <article class="task-row<?php echo $isTrashRequest ? ' trash-task-row' : ''; ?>">
                        <?php if (!$isTrashRequest): ?>
                            <div class="bulk-select-cell">
                                <input
                                    class="bulk-checkbox"
                                    type="checkbox"
                                    value="<?php echo escapeHtml($task['id']); ?>"
                                    data-bulk-task-checkbox
                                    aria-label="选择任务 <?php echo $safeTitle; ?>"
                                >
                            </div>
                        <?php endif; ?>
                        <div>
                            <h3 class="task-title"><?php echo $safeTitle; ?></h3>
                            <p class="task-summary">创建：<?php echo $safeCreatedAt; ?></p>
                        </div>
                        <p class="task-summary"><?php echo $safeSummary; ?></p>
                        <?php if ($categoryName !== ''): ?>
                            <span class="category-badge"><?php echo $safeCategoryName; ?></span>
                        <?php else: ?>
                            <span class="category-empty">未分类</span>
                        <?php endif; ?>
                        <span class="priority-badge <?php echo $priorityClass; ?>"><?php echo $safePriority; ?></span>
                        <?php
                        $taskTags = isset($task['tags']) && is_array($task['tags']) ? $task['tags'] : [];
                        ?>
                        <?php if (!empty($taskTags)): ?>
                            <div class="task-tags">
                                <?php foreach ($taskTags as $taskTag): ?>
                                    <?php
                                    $taskTagName = isset($taskTag['name']) && is_string($taskTag['name']) ? $taskTag['name'] : '';
                                    $taskTagColor = isset($taskTag['color']) && is_string($taskTag['color']) ? $taskTag['color'] : '#667085';
                                    ?>
                                    <span class="tag-badge" style="background-color: <?php echo escapeHtml($taskTagColor); ?>20; border: 1px solid <?php echo escapeHtml($taskTagColor); ?>40; color: <?php echo escapeHtml($taskTagColor); ?>;"><?php echo escapeHtml($taskTagName); ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <div class="status-cell">
                            <span class="status-badge"><?php echo $safeStatus; ?></span>
                            <?php if (!$isTrashRequest): ?>
                                <form class="status-change-form" method="post" action="index.php" data-status-form data-task-id="<?php echo escapeHtml($task['id']); ?>">
                                    <input type="hidden" name="form_action" value="status_change">
                                    <input type="hidden" name="task_id" value="<?php echo escapeHtml($task['id']); ?>">
                                    <select class="status-select" name="status" aria-label="<?php echo $safeTitle; ?> 状态">
                                        <?php foreach (ALLOWED_STATUSES as $statusOption): ?>
                                            <option value="<?php echo escapeHtml($statusOption); ?>"<?php echo $normalizedStatus === $statusOption ? ' selected' : ''; ?>>
                                                <?php echo escapeHtml($statusOption); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="status-button" type="submit">更新</button>
                                </form>
                            <?php endif; ?>
                        </div>
                        <div class="due-cell">
                            <span class="due-badge <?php echo $safeDueClass; ?>"><?php echo $safeDueLabel; ?></span>
                            <?php if ($dueState['datetime'] !== ''): ?>
                                <time class="due-time" datetime="<?php echo $safeDueDateTime; ?>"><?php echo $safeDueDescription; ?></time>
                            <?php else: ?>
                                <span class="due-time"><?php echo $safeDueDescription; ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($remindState['datetime'] !== ''): ?>
                        <div class="remind-cell">
                            <span class="remind-badge <?php echo $safeRemindClass; ?>"><?php echo $safeRemindLabel; ?></span>
                            <time class="remind-time" datetime="<?php echo $safeRemindDateTime; ?>"><?php echo $safeRemindDescription; ?></time>
                        </div>
                        <?php endif; ?>
                        <time class="created-time" datetime="<?php echo escapeHtml($task['updated_at']); ?>"><?php echo $safeUpdatedAt; ?></time>
                        <nav class="actions" aria-label="<?php echo $safeTitle; ?> 操作">
                            <?php if ($isTrashRequest): ?>
                                <form
                                    class="delete-form"
                                    method="post"
                                    action="index.php"
                                    data-trash-restore-form
                                    data-task-id="<?php echo escapeHtml($task['id']); ?>"
                                    data-task-title="<?php echo $safeTitle; ?>"
                                >
                                    <input type="hidden" name="form_action" value="restore_trash">
                                    <input type="hidden" name="task_id" value="<?php echo escapeHtml($task['id']); ?>">
                                    <button class="status-button" type="submit">恢复</button>
                                </form>
                                <form
                                    class="delete-form"
                                    method="post"
                                    action="index.php"
                                    data-permanent-delete-form
                                    data-task-id="<?php echo escapeHtml($task['id']); ?>"
                                    data-task-title="<?php echo $safeTitle; ?>"
                                >
                                    <input type="hidden" name="form_action" value="permanent_delete">
                                    <input type="hidden" name="task_id" value="<?php echo escapeHtml($task['id']); ?>">
                                    <input type="hidden" name="permanent_delete_confirmation" value="yes">
                                    <button class="delete-button" type="submit">永久删除</button>
                                </form>
                            <?php else: ?>
                                <a class="action-link" href="index.php?action=view&id=<?php echo $taskId; ?>">查看</a>
                                <a class="action-link" href="index.php?action=edit&id=<?php echo $taskId; ?>">编辑</a>
                            <?php endif; ?>
                            <?php if ($isArchiveRequest): ?>
                                <form class="delete-form" method="post" action="index.php">
                                    <input type="hidden" name="form_action" value="restore_archive">
                                    <input type="hidden" name="task_id" value="<?php echo escapeHtml($task['id']); ?>">
                                    <button class="status-button" type="submit">恢复</button>
                                </form>
                            <?php elseif (!$isTrashRequest): ?>
                                <form
                                    class="delete-form"
                                    method="post"
                                    action="index.php"
                                    data-archive-form
                                    data-task-id="<?php echo escapeHtml($task['id']); ?>"
                                    data-task-title="<?php echo $safeTitle; ?>"
                                >
                                    <input type="hidden" name="form_action" value="archive">
                                    <input type="hidden" name="task_id" value="<?php echo escapeHtml($task['id']); ?>">
                                    <button class="status-button" type="submit">归档</button>
                                </form>
                            <?php endif; ?>
                            <?php if (!$isTrashRequest): ?>
                                <form
                                    class="delete-form"
                                    method="post"
                                    action="index.php"
                                    data-delete-form
                                    data-task-id="<?php echo escapeHtml($task['id']); ?>"
                                    data-task-title="<?php echo $safeTitle; ?>"
                                >
                                    <input type="hidden" name="form_action" value="delete">
                                    <input type="hidden" name="task_id" value="<?php echo escapeHtml($task['id']); ?>">
                                    <button class="delete-button" type="submit">删除</button>
                                </form>
                            <?php endif; ?>
                        </nav>
                    </article>
                <?php endforeach; ?>

                <?php if ($paginationErrors !== []): ?>
                    <div class="pagination-errors">
                        <?php foreach ($paginationErrors as $paginationError): ?>
                            <p class="feedback error" role="alert"><?php echo escapeHtml($paginationError); ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($taskCount > 0): ?>
                    <div class="pagination-container">
                        <div class="pagination-info">
                            <?php if ($needsFullTaskList): ?>
                                当前任务总数：<?php echo $totalTaskCount; ?> 条
                            <?php else: ?>
                                第 <?php echo (int) $paginationInfo['current_page']; ?> / <?php echo (int) $paginationInfo['total_pages']; ?> 页，共 <?php echo (int) $paginationInfo['total_count']; ?> 条
                                <?php if ($hasActiveFilters): ?>
                                    （已应用筛选条件）
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <?php if (!$needsFullTaskList && $paginationInfo['total_pages'] > 1): ?>
                            <div class="pagination-controls">
                                <?php
                                $currentPage = (int) $paginationInfo['current_page'];
                                $totalPages = (int) $paginationInfo['total_pages'];
                                $pageSize = (int) $paginationInfo['page_size'];

                                $baseParams = [];
                                if ($isTrashRequest) {
                                    $baseParams['action'] = 'trash';
                                } elseif ($isArchiveRequest) {
                                    $baseParams['action'] = 'archive';
                                }
                                if ($searchKeyword !== '') {
                                    $baseParams['keyword'] = $searchKeyword;
                                }
                                if ($statusFilter !== '') {
                                    $baseParams['status'] = $statusFilter;
                                }
                                if ($priorityFilter !== '') {
                                    $baseParams['priority'] = $priorityFilter;
                                }
                                if ($tagFilter !== '') {
                                    $baseParams['tag_id'] = $tagFilter;
                                }
                                $baseParams['sort_by'] = $sortBy;
                                $baseParams['sort_order'] = $sortOrder;
                                $baseParams['page_size'] = $pageSize;

                                function buildPaginationUrl(array $params, int $page): string {
                                    $queryParts = [];
                                    foreach ($params as $key => $value) {
                                        if ($value !== '') {
                                            $queryParts[] = urlencode($key) . '=' . urlencode((string) $value);
                                        }
                                    }
                                    $queryParts[] = 'page=' . $page;
                                    return 'index.php?' . implode('&', $queryParts);
                                }

                                $firstUrl = buildPaginationUrl($baseParams, 1);
                                $prevUrl = buildPaginationUrl($baseParams, $currentPage - 1);
                                $nextUrl = buildPaginationUrl($baseParams, $currentPage + 1);
                                $lastUrl = buildPaginationUrl($baseParams, $totalPages);
                                ?>
                                <a class="pagination-button<?php echo $currentPage === 1 ? ' current' : ''; ?>" href="<?php echo escapeHtml($firstUrl); ?>"<?php echo $currentPage === 1 ? ' aria-current="page"' : ''; ?>>首页</a>
                                <a class="pagination-button" href="<?php echo escapeHtml($prevUrl); ?>"<?php echo !$paginationInfo['has_prev_page'] ? ' aria-disabled="true" style="opacity:0.5;pointer-events:none;"' : ''; ?>>上一页</a>

                                <?php
                                $windowSize = 2;
                                $startPage = max(1, $currentPage - $windowSize);
                                $endPage = min($totalPages, $currentPage + $windowSize);

                                if ($startPage > 1): ?>
                                    <a class="pagination-button" href="<?php echo escapeHtml(buildPaginationUrl($baseParams, 1)); ?>">1</a>
                                    <?php if ($startPage > 2): ?>
                                        <span style="color:var(--text-muted);padding:0 4px;">...</span>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                                    <a class="pagination-button<?php echo $i === $currentPage ? ' current' : ''; ?>" href="<?php echo escapeHtml(buildPaginationUrl($baseParams, $i)); ?>"<?php echo $i === $currentPage ? ' aria-current="page"' : ''; ?>><?php echo $i; ?></a>
                                <?php endfor; ?>

                                <?php if ($endPage < $totalPages): ?>
                                    <?php if ($endPage < $totalPages - 1): ?>
                                        <span style="color:var(--text-muted);padding:0 4px;">...</span>
                                    <?php endif; ?>
                                    <a class="pagination-button" href="<?php echo escapeHtml(buildPaginationUrl($baseParams, $totalPages)); ?>"><?php echo $totalPages; ?></a>
                                <?php endif; ?>

                                <a class="pagination-button" href="<?php echo escapeHtml($nextUrl); ?>"<?php echo !$paginationInfo['has_next_page'] ? ' aria-disabled="true" style="opacity:0.5;pointer-events:none;"' : ''; ?>>下一页</a>
                                <a class="pagination-button" href="<?php echo escapeHtml($lastUrl); ?>">末页</a>

                                <span style="color:var(--text-muted);padding:0 4px;">转到</span>
                                <input
                                    type="number"
                                    class="pagination-page-input"
                                    id="pagination-jump-input"
                                    min="1"
                                    max="<?php echo $totalPages; ?>"
                                    value="<?php echo $currentPage; ?>"
                                    aria-label="跳转到指定页码"
                                >
                                <span style="color:var(--text-muted);">页</span>
                            </div>
                        <?php elseif (!$needsFullTaskList): ?>
                            <div class="pagination-controls">
                                <span class="page-size-selector">
                                    <label for="filter-page-size-bottom">每页显示</label>
                                    <select class="select pagination-size-select" id="filter-page-size-bottom" name="page_size_bottom" onchange="var val=this.value;var url=new URL(window.location.href);url.searchParams.set('page_size',val);url.searchParams.set('page','1');window.location.href=url.toString();">
                                        <option value="10"<?php echo $pageSize === 10 ? ' selected' : ''; ?>>10 条</option>
                                        <option value="20"<?php echo $pageSize === 20 ? ' selected' : ''; ?>>20 条</option>
                                        <option value="50"<?php echo $pageSize === 50 ? ' selected' : ''; ?>>50 条</option>
                                        <option value="100"<?php echo $pageSize === 100 ? ' selected' : ''; ?>>100 条</option>
                                    </select>
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </main>
    <script>
        (function () {
            function writeClientDebugLog(operation, parameters, status, context) {
                var entry = {
                    timestamp: new Date().toISOString(),
                    operation: operation,
                    parameters: parameters,
                    status: status,
                    context: context || {}
                };
                console.debug('[task-debug]', JSON.stringify(entry));
            }

            var bulkForm = document.querySelector('[data-bulk-form]');
            var bulkSelectAll = document.querySelector('[data-bulk-select-all]');
            var bulkCheckboxes = Array.prototype.slice.call(document.querySelectorAll('[data-bulk-task-checkbox]'));
            var bulkSubmitButtons = Array.prototype.slice.call(document.querySelectorAll('[data-bulk-submit]'));
            var bulkSelectedCount = document.querySelector('[data-bulk-selected-count]');
            var bulkHiddenSelected = document.querySelector('[data-bulk-hidden-selected]');

            function getSelectedBulkTaskIds() {
                return bulkCheckboxes.filter(function (checkbox) {
                    return checkbox.checked;
                }).map(function (checkbox) {
                    return checkbox.value;
                });
            }

            function updateBulkControls(source) {
                var selectedTaskIds = getSelectedBulkTaskIds();
                var selectedCount = selectedTaskIds.length;
                if (bulkSelectedCount) {
                    bulkSelectedCount.textContent = '已选择 ' + selectedCount + ' 条';
                }
                bulkSubmitButtons.forEach(function (button) {
                    button.disabled = selectedCount === 0;
                });
                if (bulkSelectAll) {
                    bulkSelectAll.checked = selectedCount > 0 && selectedCount === bulkCheckboxes.length;
                    bulkSelectAll.indeterminate = selectedCount > 0 && selectedCount < bulkCheckboxes.length;
                }
                writeClientDebugLog('task_bulk_selection_change', {
                    selected_count: selectedCount,
                    selected_task_ids: selectedTaskIds,
                    total_visible_count: bulkCheckboxes.length
                }, selectedCount > 0 ? 'success' : 'empty', {
                    source: source || 'selection_update'
                });
            }

            if (bulkSelectAll) {
                bulkSelectAll.addEventListener('change', function () {
                    bulkCheckboxes.forEach(function (checkbox) {
                        checkbox.checked = bulkSelectAll.checked;
                    });
                    writeClientDebugLog('task_bulk_select_all', {
                        checked: bulkSelectAll.checked,
                        affected_count: bulkCheckboxes.length
                    }, 'success', {
                        source: 'select_all_checkbox'
                    });
                    updateBulkControls('select_all_checkbox');
                });
            }

            bulkCheckboxes.forEach(function (checkbox) {
                checkbox.addEventListener('change', function () {
                    writeClientDebugLog('task_bulk_select_task', {
                        task_id: checkbox.value,
                        checked: checkbox.checked
                    }, 'success', {
                        source: 'row_checkbox'
                    });
                    updateBulkControls('row_checkbox');
                });
            });

            bulkSubmitButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    if (bulkForm) {
                        bulkForm.setAttribute('data-clicked-bulk-action', button.value);
                    }
                });
            });

            if (bulkForm) {
                bulkForm.addEventListener('submit', function (event) {
                    var selectedTaskIds = getSelectedBulkTaskIds();
                    var clickedAction = bulkForm.getAttribute('data-clicked-bulk-action') || '';
                    var categorySelect = bulkForm.querySelector('select[name="bulk_category_id"]');
                    var prioritySelect = bulkForm.querySelector('select[name="bulk_priority"]');
                    if (bulkHiddenSelected) {
                        bulkHiddenSelected.innerHTML = '';
                        selectedTaskIds.forEach(function (taskId) {
                            var input = document.createElement('input');
                            input.type = 'hidden';
                            input.name = 'bulk_task_ids[]';
                            input.value = taskId;
                            bulkHiddenSelected.appendChild(input);
                        });
                    }
                    if (selectedTaskIds.length === 0) {
                        event.preventDefault();
                        writeClientDebugLog('task_bulk_operation_blocked', {
                            bulk_action: clickedAction,
                            selected_count: 0
                        }, 'failed', {
                            reason: 'empty_selection',
                            request_submitted: false
                        });
                        alert('请先选择要批量操作的任务。');
                        updateBulkControls('submit_blocked_empty_selection');
                        return;
                    }
                    if (clickedAction === 'delete') {
                        var confirmed = window.confirm('确认将已选择的 ' + selectedTaskIds.length + ' 条任务移入回收站？之后可在回收站恢复或永久删除。');
                        if (!confirmed) {
                            event.preventDefault();
                            writeClientDebugLog('task_bulk_delete_cancel', {
                                bulk_action: clickedAction,
                                selected_count: selectedTaskIds.length,
                                selected_task_ids: selectedTaskIds
                            }, 'cancelled', {
                                request_submitted: false
                            });
                            return;
                        }
                    }
                    writeClientDebugLog('task_bulk_operation_submit', {
                        bulk_action: clickedAction,
                        selected_count: selectedTaskIds.length,
                        selected_task_ids: selectedTaskIds,
                        target_category_id: categorySelect ? categorySelect.value : '',
                        target_priority: prioritySelect ? prioritySelect.value : ''
                    }, 'started', {
                        source: 'bulk_toolbar',
                        request_submitted: true
                    });
                });
                updateBulkControls('initial_state');
            }

            var bulkResultParams = new URLSearchParams(window.location.search);
            if (bulkResultParams.has('bulk_result')) {
                writeClientDebugLog('task_bulk_operation_result_notice', {
                    bulk_result: bulkResultParams.get('bulk_result') || '',
                    bulk_action: bulkResultParams.get('bulk_action') || '',
                    success_count: bulkResultParams.get('bulk_success') || '0',
                    failed_count: bulkResultParams.get('bulk_failed') || '0',
                    failure_reasons: bulkResultParams.get('bulk_reasons') || ''
                }, bulkResultParams.get('bulk_result') === 'success' ? 'success' : (bulkResultParams.get('bulk_result') === 'partial' ? 'partial' : 'failed'), {
                    source: 'redirect_query'
                });
            }

            var deleteForms = document.querySelectorAll('[data-delete-form]');
            deleteForms.forEach(function (form) {
                form.addEventListener('submit', function (event) {
                    var taskId = form.getAttribute('data-task-id') || '';
                    var taskTitle = form.getAttribute('data-task-title') || '';

                    writeClientDebugLog('task_delete_confirm', {
                        task_id: taskId,
                        title_length: taskTitle.length
                    }, 'started', {
                        source: 'browser_confirm'
                    });

                    var confirmed = window.confirm('确认将任务“' + taskTitle + '”移入回收站？之后可在回收站恢复或永久删除。');
                    if (!confirmed) {
                        event.preventDefault();
                        writeClientDebugLog('task_delete_cancel', {
                            task_id: taskId,
                            title_length: taskTitle.length
                        }, 'cancelled', {
                            request_submitted: false
                        });
                        return;
                    }

                    writeClientDebugLog('task_delete_confirm', {
                        task_id: taskId,
                        title_length: taskTitle.length
                    }, 'success', {
                        request_submitted: true
                    });
                });
            });

            var trashRestoreForms = document.querySelectorAll('[data-trash-restore-form]');
            trashRestoreForms.forEach(function (form) {
                form.addEventListener('submit', function () {
                    var taskId = form.getAttribute('data-task-id') || '';
                    var taskTitle = form.getAttribute('data-task-title') || '';
                    writeClientDebugLog('task_trash_restore_submit', {
                        task_id: taskId,
                        title_length: taskTitle.length
                    }, 'started', {
                        source: 'trash_restore_form',
                        request_submitted: true
                    });
                });
            });

            var permanentDeleteForms = document.querySelectorAll('[data-permanent-delete-form]');
            permanentDeleteForms.forEach(function (form) {
                form.addEventListener('submit', function (event) {
                    var taskId = form.getAttribute('data-task-id') || '';
                    var taskTitle = form.getAttribute('data-task-title') || '';
                    writeClientDebugLog('task_trash_permanent_delete_confirm', {
                        task_id: taskId,
                        title_length: taskTitle.length
                    }, 'started', {
                        source: 'browser_confirm'
                    });

                    var firstConfirmed = window.confirm('永久删除任务“' + taskTitle + '”后不可从普通页面或回收站恢复。继续执行永久删除。');
                    if (!firstConfirmed) {
                        event.preventDefault();
                        writeClientDebugLog('task_trash_permanent_delete_cancel', {
                            task_id: taskId,
                            title_length: taskTitle.length
                        }, 'cancelled', {
                            stage: 'first_confirm',
                            request_submitted: false
                        });
                        return;
                    }

                    var secondConfirmed = window.confirm('二次确认：立即永久删除“' + taskTitle + '”。该操作不可撤销。');
                    if (!secondConfirmed) {
                        event.preventDefault();
                        writeClientDebugLog('task_trash_permanent_delete_cancel', {
                            task_id: taskId,
                            title_length: taskTitle.length
                        }, 'cancelled', {
                            stage: 'second_confirm',
                            request_submitted: false
                        });
                        return;
                    }

                    writeClientDebugLog('task_trash_permanent_delete_confirm', {
                        task_id: taskId,
                        title_length: taskTitle.length
                    }, 'success', {
                        source: 'browser_confirm',
                        confirmation_steps: 2,
                        request_submitted: true
                    });
                });
            });

            var statusForms = document.querySelectorAll('[data-status-form]');
            statusForms.forEach(function (form) {
                form.addEventListener('submit', function () {
                    var select = form.querySelector('select[name="status"]');
                    writeClientDebugLog('task_status_change_submit', {
                        task_id: form.getAttribute('data-task-id') || '',
                        submitted_status: select ? select.value : ''
                    }, 'started', {
                        source: 'list_inline_form'
                    });
                });
            });

            var taskForms = document.querySelectorAll('[data-task-form]');
            taskForms.forEach(function (form) {
                form.addEventListener('submit', function () {
                    var dueAtInput = form.querySelector('input[name="due_at"]');
                    var categorySelect = form.querySelector('select[name="category_id"]');
                    var actionInput = form.querySelector('input[name="form_action"]');
                    var taskIdInput = form.querySelector('input[name="task_id"]');
                    writeClientDebugLog('task_due_at_submit', {
                        task_id: taskIdInput ? taskIdInput.value : '',
                        form_action: actionInput ? actionInput.value : form.getAttribute('data-form-action') || '',
                        submitted_due_at: dueAtInput ? dueAtInput.value : ''
                    }, 'started', {
                        source: 'task_form'
                    });
                    writeClientDebugLog('task_category_submit', {
                        task_id: taskIdInput ? taskIdInput.value : '',
                        form_action: actionInput ? actionInput.value : form.getAttribute('data-form-action') || '',
                        category_id: categorySelect ? categorySelect.value : ''
                    }, 'started', {
                        source: 'task_form'
                    });
                });
            });

            var categoryForms = document.querySelectorAll('[data-category-form]');
            categoryForms.forEach(function (form) {
                form.addEventListener('submit', function () {
                    var action = form.getAttribute('data-category-action') || '';
                    var categoryIdInput = form.querySelector('input[name="manage_category_id"]');
                    var categoryNameInput = form.querySelector('input[name="category_name"]');
                    writeClientDebugLog('category_' + action + '_submit', {
                        category_id: categoryIdInput ? categoryIdInput.value : '',
                        name_length: categoryNameInput ? categoryNameInput.value.trim().length : 0
                    }, 'started', {
                        source: 'category_form'
                    });
                });
            });

            var categoryDeleteForms = document.querySelectorAll('[data-category-delete-form]');
            categoryDeleteForms.forEach(function (form) {
                form.addEventListener('submit', function () {
                    writeClientDebugLog('category_delete_submit', {
                        category_id: form.getAttribute('data-category-id') || '',
                        category_name: form.getAttribute('data-category-name') || ''
                    }, 'started', {
                        source: 'category_delete_form'
                    });
                });
            });

            var paginationJumpInput = document.getElementById('pagination-jump-input');
            if (paginationJumpInput) {
                paginationJumpInput.addEventListener('keypress', function (event) {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        var targetPage = parseInt(paginationJumpInput.value, 10);
                        var maxPage = parseInt(paginationJumpInput.getAttribute('max'), 10);
                        if (!isNaN(targetPage) && targetPage >= 1 && targetPage <= maxPage) {
                            var url = new URL(window.location.href);
                            url.searchParams.set('page', targetPage.toString());
                            writeClientDebugLog('pagination_jump', {
                                target_page: targetPage,
                                current_url: window.location.href
                            }, 'started');
                            window.location.href = url.toString();
                        } else {
                            writeClientDebugLog('pagination_jump_invalid', {
                                target_page: paginationJumpInput.value,
                                max_page: maxPage
                            }, 'failed', {
                                reason: 'invalid_page_number'
                            });
                            alert('请输入有效的页码（1-' + maxPage + '）');
                        }
                    }
                });
            }

            var subtaskEditBtns = document.querySelectorAll('.subtask-edit-btn');
            subtaskEditBtns.forEach(function (btn) {
                btn.addEventListener('click', function (event) {
                    var subtaskItem = btn.closest('.subtask-item');
                    if (!subtaskItem) return;

                    var toggleForm = subtaskItem.querySelector('.subtask-toggle-form');
                    var editForm = subtaskItem.querySelector('.subtask-edit-form');
                    var titleSpan = subtaskItem.querySelector('.subtask-title:not(.completed)');
                    var actionsDiv = subtaskItem.querySelector('.subtask-actions');

                    if (toggleForm) toggleForm.style.display = 'none';
                    if (actionsDiv) actionsDiv.style.display = 'none';
                    if (titleSpan) titleSpan.style.display = 'none';
                    if (editForm) {
                        editForm.style.display = 'inline-flex';
                        var editInput = editForm.querySelector('.subtask-edit-input');
                        if (editInput) {
                            editInput.focus();
                            editInput.select();
                        }
                    }

                    writeClientDebugLog('subtask_edit_start', {
                        subtask_id: subtaskItem.getAttribute('data-subtask-id') || ''
                    }, 'started', {
                        source: 'inline_edit_button'
                    });
                });
            });

            var subtaskCancelBtns = document.querySelectorAll('.subtask-cancel-btn');
            subtaskCancelBtns.forEach(function (btn) {
                btn.addEventListener('click', function (event) {
                    var subtaskItem = btn.closest('.subtask-item');
                    if (!subtaskItem) return;

                    var toggleForm = subtaskItem.querySelector('.subtask-toggle-form');
                    var editForm = subtaskItem.querySelector('.subtask-edit-form');
                    var titleSpan = subtaskItem.querySelector('.subtask-title');
                    var actionsDiv = subtaskItem.querySelector('.subtask-actions');
                    var editInput = editForm ? editForm.querySelector('.subtask-edit-input') : null;
                    var originalTitle = editInput ? editInput.getAttribute('value') : '';

                    if (toggleForm) toggleForm.style.display = 'inline-flex';
                    if (actionsDiv) actionsDiv.style.display = '';
                    if (titleSpan) {
                        titleSpan.style.display = '';
                    }
                    if (editForm) {
                        editForm.style.display = 'none';
                        if (editInput) editInput.value = originalTitle;
                    }

                    writeClientDebugLog('subtask_edit_cancel', {
                        subtask_id: subtaskItem.getAttribute('data-subtask-id') || ''
                    }, 'cancelled', {
                        source: 'inline_edit_cancel_button'
                    });
                });
            });

            var subtaskEditForms = document.querySelectorAll('.subtask-edit-form');
            subtaskEditForms.forEach(function (form) {
                form.addEventListener('submit', function () {
                    var subtaskIdInput = form.querySelector('input[name="subtask_id"]');
                    var subtaskTitleInput = form.querySelector('input[name="subtask_title"]');
                    writeClientDebugLog('subtask_edit_submit', {
                        subtask_id: subtaskIdInput ? subtaskIdInput.value : '',
                        title_length: subtaskTitleInput ? subtaskTitleInput.value.trim().length : 0
                    }, 'started', {
                        source: 'inline_edit_form'
                    });
                });
            });

            var subtaskDeleteForms = document.querySelectorAll('.subtask-delete-form');
            subtaskDeleteForms.forEach(function (form) {
                form.addEventListener('submit', function (event) {
                    var subtaskIdInput = form.querySelector('input[name="subtask_id"]');
                    writeClientDebugLog('subtask_delete_confirm', {
                        subtask_id: subtaskIdInput ? subtaskIdInput.value : ''
                    }, 'started', {
                        source: 'inline_delete_form'
                    });
                });
            });

            var subtaskToggleForms = document.querySelectorAll('.subtask-toggle-form');
            subtaskToggleForms.forEach(function (form) {
                form.addEventListener('submit', function () {
                    var subtaskIdInput = form.querySelector('input[name="subtask_id"]');
                    writeClientDebugLog('subtask_toggle_submit', {
                        subtask_id: subtaskIdInput ? subtaskIdInput.value : ''
                    }, 'started', {
                        source: 'inline_toggle_form'
                    });
                });
            });

            var subtaskAddForms = document.querySelectorAll('.subtask-add-form form');
            subtaskAddForms.forEach(function (form) {
                form.addEventListener('submit', function () {
                    var titleInput = form.querySelector('input[name="subtask_title"]');
                    writeClientDebugLog('subtask_create_submit', {
                        title_length: titleInput ? titleInput.value.trim().length : 0
                    }, 'started', {
                        source: 'inline_add_form'
                    });
                });
            });
        }());
    </script>
</body>
</html>
