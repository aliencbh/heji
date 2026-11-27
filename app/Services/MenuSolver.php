<?php
namespace App\Services;
use DateTimeImmutable;
use Illuminate\Validation\ValidationException;
class MenuSolver {
    public const SLOTS=\App\Support\DbValues::SLOTS;
    public function slots(string $date,array $weekend): array {
        $dow=(int)(new DateTimeImmutable($date))->format('N');
        return in_array($dow,$weekend,true) ? self::SLOTS : array_slice(self::SLOTS,0,3);
    }
    public function eligible(array $r,string $slot,string $date,int $first): bool {
        if (!$r['tasty'] || !$r['items'] || $r['kind']!==$slot) return false;
        if ($slot!==\App\Support\DbValues::MEAT) return true;
        $offset=((int)(new DateTimeImmutable($date))->format('N')-$first+7)%7;
        $fixed=\App\Support\DbValues::FIXED;
        return $offset<3 ? $r['family']===$fixed[$offset] : !in_array($r['family'],$fixed,true);
    }
    public function solve(array $dates,array $recipes,int $first,array $weekend,array $preferred,array $skipped,array $before,array $after,array $ruleDays=[]): array {
        $byId = array_column($recipes, null, 'id');
        $pool = array_values($byId);
        shuffle($pool);
        $weekdayChoices = [];
        $positions = [];
        $plan = array_fill_keys($dates, []);

        foreach ($dates as $index => $day) {
            if (isset($skipped[$day])) continue;
            $ruleDay = $ruleDays[$day] ?? $day;
            $weekday = (new DateTimeImmutable($ruleDay))->format('N');
            $slots = $this->slots($ruleDay, $weekend);
            foreach ($slots as $slot) {
                if (!isset($weekdayChoices[$weekday][$slot])) {
                    $eligible = array_filter($pool, fn($r) => $this->eligible($r, $slot, $ruleDay, $first));
                    $weekdayChoices[$weekday][$slot] = array_column($eligible, 'id');
                }
                $choices = $weekdayChoices[$weekday][$slot];
                $wanted = $preferred[$day][$slot] ?? null;
                if ($wanted !== null && isset($ruleDays[$day])) {
                    $choices = in_array($wanted, $choices, true) ? [$wanted] : [];
                } elseif ($wanted !== null && in_array($wanted, $choices, true)) {
                    $choices = array_values(array_unique(array_merge([$wanted], $choices)));
                }
        if (!$choices) throw ValidationException::withMessages(['menu' => __('messages.menu_missing_day', ['day' => $day, 'slot' => $slot])]);
                $positions[] = [$index, $slot, $choices, $slot === end($slots)];
            }
        }

        // The explicit cursor lets a full year use the same PHP call depth as one week.
        $position = 0;
        $furthest = 0;
        $backtracks = 0;
        $nextChoice = [];
        $ingredients = [];
        $lastDay = count($dates) - 1;
        while ($position < count($positions)) {
            [$index, $slot, $choices, $lastSlot] = $positions[$position];
            $day = $dates[$index];
            $previous = $index === 0 ? $before : ($ingredients[$index - 1] ?? []);
            $candidate = $nextChoice[$position] ?? 0;
            while ($candidate < count($choices)) {
                $recipe = $byId[$choices[$candidate++]];
                if (array_intersect($recipe['items'], $previous)) continue;
                if ($index === $lastDay && array_intersect($recipe['items'], $after)) continue;

                $plan[$day][$slot] = $recipe['id'];
                if ($lastSlot) {
                    $items = [];
                    foreach ($plan[$day] as $id) $items = array_merge($items, $byId[$id]['items']);
                    $ingredients[$index] = array_unique($items);
                }
                $nextChoice[$position] = $candidate;
                ++$position;
                if ($position > $furthest) {
                    $furthest = $position;
                    $backtracks = 0;
                }
                continue 2;
            }

            unset($nextChoice[$position], $plan[$day][$slot]);
            if ($position === 0) throw ValidationException::withMessages(['menu'=>__('messages.menu_no_combo')]);
            // Only failed alternatives consume this budget. Advancing the calendar never does.
            if (++$backtracks > 200000) throw ValidationException::withMessages(['menu'=>__('messages.menu_backtrack')]);
            --$position;
        }
        return $plan;
    }
}
