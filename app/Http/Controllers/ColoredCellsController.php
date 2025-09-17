<?php

namespace App\Http\Controllers;

use App\Models\ColoredCell;
use App\Models\Status;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class ColoredCellsController extends Controller
{
    /**
     * Массовое закрашивание ячеек (upsert по уникальному ключу project/batch/image/date)
     */
    public function bulkColor(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'items' => ['required', 'array', 'min:1'],
            'items.*.project_id' => ['required', 'integer', 'exists:projects,id'],
            'items.*.batch_id' => ['required', 'integer', 'exists:batches,id'],
            'items.*.image_id' => ['required', 'integer', 'exists:images,id'],
            'items.*.status_id' => ['required', 'integer', 'exists:statuses,id'],
            'items.*.date' => ['required', 'date'],
            'items.*.completed' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $now = now();
        $rows = [];
        foreach ($request->input('items') as $item) {
            $rows[] = [
                'project_id' => (int) $item['project_id'],
                'batch_id' => (int) $item['batch_id'],
                'image_id' => (int) $item['image_id'],
                'status_id' => (int) $item['status_id'],
                'date' => $item['date'],
                'completed' => (bool) ($item['completed'] ?? false),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // upsert по уникальному ключу: project_id, batch_id, image_id, date
        ColoredCell::upsert(
            $rows,
            ['project_id', 'batch_id', 'image_id', 'date'],
            ['status_id', 'completed', 'updated_at']
        );

        // Вернём актуальные данные из БД
        $result = ColoredCell::query()
            ->where(function ($query) use ($rows) {
                foreach ($rows as $r) {
                    $query->orWhere(function ($q) use ($r) {
                        $q->where('project_id', $r['project_id'])
                            ->where('batch_id', $r['batch_id'])
                            ->where('image_id', $r['image_id'])
                            ->where('date', $r['date']);
                    });
                }
            })
            ->get();

        return response()->json([
            'message' => 'Cells colored successfully',
            'cells' => $result,
        ]);
    }

    /**
     * Массовое удаление ячеек по набору ключей
     */
    public function bulkDelete(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'items' => ['required', 'array', 'min:1'],
            'items.*.project_id' => ['required', 'integer', 'exists:projects,id'],
            'items.*.batch_id' => ['required', 'integer', 'exists:batches,id'],
            'items.*.image_id' => ['required', 'integer', 'exists:images,id'],
            'items.*.date' => ['required', 'date'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $items = $request->input('items');

        // Получим id статусов Delay и Weekend
        $statuses = Status::query()->whereIn('name', ['Delay', 'Weekend'])->get()->keyBy('name');
        $delayStatusId = optional($statuses->get('Delay'))->id;
        $weekendStatusId = optional($statuses->get('Weekend'))->id;

        // Найдём все строки, которые соответствуют переданным ключам
        $matched = ColoredCell::query()
            ->where(function ($query) use ($items) {
                foreach ($items as $r) {
                    $query->orWhere(function ($q) use ($r) {
                        $q->where('project_id', (int) $r['project_id'])
                            ->where('batch_id', (int) $r['batch_id'])
                            ->where('image_id', (int) $r['image_id'])
                            ->where('date', $r['date']);
                    });
                }
            })
            ->get(['id', 'project_id', 'batch_id', 'image_id', 'status_id', 'date', 'completed']);

        if ($matched->isEmpty()) {
            return response()->json([
                'message' => 'Cells deleted successfully',
                'deleted' => 0,
            ]);
        }

        // Разделим по типам
        $weekendRows = $matched->filter(function ($c) use ($weekendStatusId) {
            return $weekendStatusId !== null && (int) $c->status_id === (int) $weekendStatusId;
        });
        $delayRows = $matched->filter(function ($c) use ($delayStatusId) {
            return $delayStatusId !== null && (int) $c->status_id === (int) $delayStatusId;
        });
        $normalRows = $matched->reject(function ($c) use ($delayStatusId, $weekendStatusId) {
            return ($delayStatusId !== null && (int) $c->status_id === (int) $delayStatusId)
                || ($weekendStatusId !== null && (int) $c->status_id === (int) $weekendStatusId);
        });

        $deletedCount = 0;

        DB::beginTransaction();
        try {
            // 1) Удаляем обычные ячейки (не Delay, не Weekend)
            if ($normalRows->isNotEmpty()) {
                $deletedCount += ColoredCell::query()->whereIn('id', $normalRows->pluck('id'))->delete();
            }

            // 2) Обрабатываем удаление Delay: сгруппируем по картинкам и сдвинем будущие незавершённые задачи назад
            if ($delayRows->isNotEmpty()) {
                $groups = $delayRows->groupBy(function ($c) {
                    return $c->project_id . ':' . $c->batch_id . ':' . $c->image_id;
                });

                foreach ($groups as $key => $rows) {
                    // Сортируем удаляемые даты Delay по возрастанию
                    $datesToRemove = $rows->map(function ($c) {
                        return $c->date instanceof Carbon ? $c->date->copy()->startOfDay() : Carbon::parse($c->date)->startOfDay();
                    })->sort()->values();

                    // Удаляем сами Delay-ячейки
                    $deletedCount += ColoredCell::query()->whereIn('id', $rows->pluck('id'))->delete();

                    // Найдём будущие незавершённые задачи (не Delay) этой картинки, которые идут после минимальной удалённой даты
                    [$pId, $bId, $iId] = array_map('intval', explode(':', $key));
                    $minRemovedDate = $datesToRemove->first();

                    $toShift = ColoredCell::query()
                        ->where('project_id', $pId)
                        ->where('batch_id', $bId)
                        ->where('image_id', $iId)
                        ->where('completed', false)
                        ->when($delayStatusId !== null, function ($q) use ($delayStatusId) {
                            $q->where('status_id', '!=', $delayStatusId);
                        })
                        ->whereDate('date', '>', $minRemovedDate->toDateString())
                        ->orderBy('date')
                        ->get(['id', 'date']);

                    if ($toShift->isNotEmpty()) {
                        // Для каждой задачи вычислим, сколько удалённых Delay-дней строго раньше её даты
                        $removedDateStrings = $datesToRemove->map->toDateString()->all();
                        foreach ($toShift as $row) {
                            $rowDate = $row->date instanceof Carbon ? $row->date->copy()->startOfDay() : Carbon::parse($row->date)->startOfDay();
                            $k = 0;
                            foreach ($removedDateStrings as $rem) {
                                if ($rowDate->gt(Carbon::parse($rem)->startOfDay())) { $k++; } else { break; }
                            }
                            if ($k > 0) {
                                $target = $rowDate->copy()->subDays($k);
                                // Избежать нарушения уникальности: ищем ближайшую свободную дату, удаляя Delay если встречаем
                                while (true) {
                                    $existing = ColoredCell::query()
                                        ->where('project_id', $pId)
                                        ->where('batch_id', $bId)
                                        ->where('image_id', $iId)
                                        ->whereDate('date', $target->toDateString())
                                        ->first(['id', 'status_id']);
                                    if (!$existing) {
                                        break;
                                    }
                                    if ($delayStatusId !== null && (int) $existing->status_id === (int) $delayStatusId) {
                                        // Удаляем задержку, чтобы освободить слот
                                        ColoredCell::query()->where('id', $existing->id)->delete();
                                        break;
                                    }
                                    // Слот занят не-Delay — смещаем целевую дату вперёд на 1 день
                                    $target->addDay();
                                    // Защитимся от бесконечного цикла: если дошли до исходной даты — прекращаем
                                    if ($target->gte($rowDate)) { break; }
                                }
                                $newDate = $target->toDateString();
                                if ($newDate !== $rowDate->toDateString()) {
                                    ColoredCell::query()->where('id', $row->id)->update(['date' => $newDate]);
                                }
                            }
                        }
                    }
                }
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to delete cells',
                'error' => $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'message' => 'Cells deleted successfully',
            'deleted' => $deletedCount,
            'skipped_weekend' => $weekendRows->count(),
        ]);
    }

    /**
     * Получение всех ячеек за период по конкретному изображению (в контексте проекта и батча)
     */
    public function byImageAndPeriod(int $projectId, int $batchId, int $imageId, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $from = $request->input('from');
        $to = $request->input('to');

        $cells = ColoredCell::query()
            ->where('project_id', $projectId)
            ->where('batch_id', $batchId)
            ->where('image_id', $imageId)
            ->whereBetween('date', [$from, $to])
            ->orderBy('date')
            ->get();

        return response()->json([
            'cells' => $cells,
        ]);
    }
    public function delayByImageAndPeriod (int $projectId, int $batchId, int $imageId, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $from = Carbon::parse($request->input('from'))->startOfDay();
        $to = Carbon::parse($request->input('to'))->endOfDay();

        // Найдём статус Delay
        $delayStatus = Status::query()->where('name', 'Delay')->first();
        if (!$delayStatus) {
            return response()->json([
                'message' => 'Status "Delay" not found',
            ], 404);
        }

        // Просрочка считается до сегодняшнего дня включительно
        $today = now()->startOfDay();

        // Берём все ячейки по изображению (для корректного построения групп и сдвигов)
        $allCells = ColoredCell::query()
            ->where('project_id', $projectId)
            ->where('batch_id', $batchId)
            ->where('image_id', $imageId)
            ->orderBy('date')
            ->get();

        if ($allCells->isEmpty()) {
            return response()->json([
                'cells' => [],
            ]);
        }

        // Построим непрерывные группы (по одинаковому status_id на соседних датах)
        $groups = [];
        $currentGroup = null;
        $prevDate = null;

        foreach ($allCells as $cell) {
            $cellDate = $cell->date instanceof Carbon ? $cell->date->copy()->startOfDay() : Carbon::parse($cell->date)->startOfDay();
            if ($currentGroup === null) {
                $currentGroup = [
                    'status_id' => (int) $cell->status_id,
                    'items' => [$cell],
                    'start' => $cellDate->copy(),
                    'end' => $cellDate->copy(),
                ];
            } else {
                $isConsecutive = $prevDate !== null && $cellDate->equalTo($prevDate->copy()->addDay());
                $sameStatus = (int) $cell->status_id === (int) $currentGroup['status_id'];
                if ($isConsecutive && $sameStatus) {
                    $currentGroup['items'][] = $cell;
                    $currentGroup['end'] = $cellDate->copy();
                } else {
                    // Закрываем предыдущую группу
                    $groups[] = $currentGroup;
                    // Начинаем новую
                    $currentGroup = [
                        'status_id' => (int) $cell->status_id,
                        'items' => [$cell],
                        'start' => $cellDate->copy(),
                        'end' => $cellDate->copy(),
                    ];
                }
            }
            $prevDate = $cellDate;
        }
        if ($currentGroup !== null) {
            $groups[] = $currentGroup;
        }

        // Для каждой группы определим, завершена ли она полностью
        foreach ($groups as &$g) {
            $allCompleted = true;
            foreach ($g['items'] as $it) {
                if (!$it->completed) { $allCompleted = false; break; }
            }
            $g['completed'] = $allCompleted;
        }
        unset($g);

        // Находим САМУЮ РАННЮЮ незавершённую группу, которая закончилась до вчера
        // и у которой НЕТ впереди ни одной полностью завершённой группы
        $overdueIdx = null;
        for ($i = 0; $i < count($groups); $i++) {
            $g = $groups[$i];
            if ((int) $g['status_id'] === (int) $delayStatus->id) { continue; }
            if ($g['completed'] === true) { continue; }
            if (!$g['end']->lt($today)) { continue; }
            // Проверяем, есть ли впереди завершённая группа
            $hasCompletedAhead = false;
            for ($j = $i + 1; $j < count($groups); $j++) {
                if ($groups[$j]['completed'] === true) {
                    $hasCompletedAhead = true;
                    break;
                }
            }
            if ($hasCompletedAhead) {
                // По важному правилу для неё не считаем дилей
                continue;
            }
            $overdueIdx = $i;
            break; // берём самую раннюю подходящую
        }

        if ($overdueIdx === null) {
            // Нечего сдвигать/добавлять по правилам
            $cells = ColoredCell::query()
                ->where('project_id', $projectId)
                ->where('batch_id', $batchId)
                ->where('image_id', $imageId)
                ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
                ->orderBy('date')
                ->get();

            return response()->json([
                'cells' => $cells,
            ]);
        }

        $overdueGroup = $groups[$overdueIdx];
        $delayDays = $overdueGroup['end']->diffInDays($today);

        if ($delayDays <= 0) {
            $cells = ColoredCell::query()
                ->where('project_id', $projectId)
                ->where('batch_id', $batchId)
                ->where('image_id', $imageId)
                ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
                ->orderBy('date')
                ->get();

            return response()->json([
                'cells' => $cells,
            ]);
        }

        // Диапазон для вставки Delay: (end+1) .. сегодня
        $delayStart = $overdueGroup['end']->copy()->addDay();
        $delayEnd = $today->copy();

        // Идемпотентность: считаем, сколько Delay-дней уже существует, и сдвигаем только на дельту
        $desiredDelayDays = 0;
        if ($delayStart->lte($delayEnd)) {
            // включительно: количество дней в окне просрочки
            $desiredDelayDays = $delayStart->diffInDays($delayEnd) + 1;
        }

        $existingDelayDays = 0;
        if ($desiredDelayDays > 0) {
            $existingDelayDays = ColoredCell::query()
                ->where('project_id', $projectId)
                ->where('batch_id', $batchId)
                ->where('image_id', $imageId)
                ->where('status_id', $delayStatus->id)
                ->whereBetween('date', [$delayStart->toDateString(), $delayEnd->toDateString()])
                ->count();
        }

        $additionalDays = max(0, $desiredDelayDays - $existingDelayDays);

        if ($additionalDays === 0) {
            // Уже всё сдвинуто и Delay-дни добавлены на текущую дату — ничего не делаем
            $cells = ColoredCell::query()
                ->where('project_id', $projectId)
                ->where('batch_id', $batchId)
                ->where('image_id', $imageId)
                ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
                ->orderBy('date')
                ->get();

            return response()->json([
                'cells' => $cells,
            ]);
        }

        DB::beginTransaction();
        try {
            // 1) Сдвигаем вперёд незавершённые задачи после просроченной группы до первого выполненного блока (если есть)
            $shiftQuery = ColoredCell::query()
                ->where('project_id', $projectId)
                ->where('batch_id', $batchId)
                ->where('image_id', $imageId)
                ->where('status_id', '!=', $delayStatus->id)
                ->where('completed', false)
                ->whereDate('date', '>=', $overdueGroup['end']->copy()->addDay()->toDateString());

            // Получим ID и текущие даты для безопасного сдвига (в порядке убывания дат)
            $toShift = $shiftQuery->orderBy('date', 'desc')->get(['id', 'date']);
            foreach ($toShift as $row) {
                $rowDate = $row->date instanceof Carbon ? $row->date->copy()->startOfDay() : Carbon::parse($row->date)->startOfDay();
                $target = $rowDate->copy()->addDays($additionalDays);
                while (true) {
                    $conflict = ColoredCell::query()
                        ->where('project_id', $projectId)
                        ->where('batch_id', $batchId)
                        ->where('image_id', $imageId)
                        ->whereDate('date', $target->toDateString())
                        ->first(['id', 'status_id']);
                    if (!$conflict) { break; }
                    if ((int) $conflict->status_id === (int) $delayStatus->id) {
                        // Освобождаем слот, удалив Delay
                        ColoredCell::query()->where('id', $conflict->id)->delete();
                        break;
                    }
                    // Конфликт с не-Delay, двигаем дальше
                    $target->addDay();
                    if ($target->diffInDays($rowDate) > 365) { break; }
                }
                $newDate = $target->toDateString();
                if ($newDate !== $rowDate->toDateString()) {
                    ColoredCell::query()->where('id', $row->id)->update(['date' => $newDate]);
                }
            }

            // 2) Вставляем Delay на пустые даты окна просрочки
            if ($delayStart->lte($delayEnd)) {
                $rows = [];
                $cursor = $delayStart->copy();
                while ($cursor->lte($delayEnd)) {
                    $rows[] = [
                        'project_id' => $projectId,
                        'batch_id' => $batchId,
                        'image_id' => $imageId,
                        'status_id' => $delayStatus->id,
                        'date' => $cursor->toDateString(),
                        'completed' => false,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                    $cursor->addDay();
                }

                // upsert, чтобы не дублировать существующие Delay
                if (!empty($rows)) {
                    ColoredCell::upsert(
                        $rows,
                        ['project_id', 'batch_id', 'image_id', 'date'],
                        ['status_id', 'completed', 'updated_at']
                    );
                }
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to apply delay logic',
                'error' => $e->getMessage(),
            ], 500);
        }

        // Возвращаем актуальные данные за указанный период
        $cells = ColoredCell::query()
            ->where('project_id', $projectId)
            ->where('batch_id', $batchId)
            ->where('image_id', $imageId)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('date')
            ->get();

        return response()->json([
            'cells' => $cells,
        ]);
    }
}
