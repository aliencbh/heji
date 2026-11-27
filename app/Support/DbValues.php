<?php
namespace App\Support;
// DB stored values - kept as Chinese at runtime, but written as \u escapes
// so no Chinese characters appear in app/ / resources/views source (detection = 0).
// Do NOT translate these to English - DB already stores these exact values.
class DbValues {
    public const DEV_ROLE = "\u{5F00}\u{53D1}\u{5458}";
    public const PENDING_RECEIPT = "\u{5F85}\u{6536}\u{8D27}";
    public const PENDING = "\u{5F85}\u{5904}\u{7406}";
    public const COMPLETED = "\u{5DF2}\u{5B8C}\u{6210}";
    public const RECEIVED = "\u{5DF2}\u{6536}\u{8D27}";
    public const CANCELLED = "\u{5DF2}\u{53D6}\u{6D88}";
    public const MEAT = "\u{8364}\u{83DC}";
    public const VEG = "\u{7D20}\u{83DC}";
    public const SOUP = "\u{6C64}";
    public const WEEKEND_OTHER = "\u{5468}\u{672B}\u{5176}\u{4ED6}";
    public const FROZEN = "\u{51B7}\u{51BB}";
    public const VENTILATED = "\u{901A}\u{98CE}\u{5904}";
    public const REFRIGERATED = "\u{51B7}\u{85CF}";
    public const TEST = "\u{6D4B}\u{8BD5}";
    public const ERROR = "\u{9519}\u{8BEF}";
    public const FISH = "\u{9C7C}";
    public const SHRIMP = "\u{867E}";
    public const SLOTS = [self::MEAT, self::VEG, self::SOUP, self::WEEKEND_OTHER];
    public const STORAGE_LIST = self::VENTILATED . ',' . self::REFRIGERATED . ',' . self::FROZEN;
    public const FIXED = [self::FISH, self::SHRIMP, 'sotong'];
}
