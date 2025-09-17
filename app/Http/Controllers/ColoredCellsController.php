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
        // Разрешаем удаление либо по ID, либо по составному ключу (project_id, batch_id, image_id, date)
        $validator = Validator::make($request->all(), [
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['nullable', 'integer', 'exists:colored_cells,id'],
            'items.*.project_id' => ['required_without:items.*.id', 'integer', 'exists:projects,id'],
            'items.*.batch_id' => ['required_without:items.*.id', 'integer', 'exists:batches,id'],
            'items.*.image_id' => ['required_without:items.*.id', 'integer', 'exists:images,id'],
            'items.*.date' => ['required_without:items.*.id', 'date'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $items = $request->input('items');

        $ids = [];
        $composites = [];
        foreach ($items as $r) {
            if (isset($r['id']) && $r['id']) {
                $ids[] = (int) $r['id'];
            } else {
                $composites[] = [
                    'project_id' => (int) $r['project_id'],
                    'batch_id' => (int) $r['batch_id'],
                    'image_id' => (int) $r['image_id'],
                    'date' => $r['date'],
                ];
            }
        }

        $deleted = ColoredCell::query()
            ->where(function ($query) use ($ids, $composites) {
                if (!empty($ids)) {
                    $query->orWhereIn('id', $ids);
                }
                if (!empty($composites)) {
                    foreach ($composites as $r) {
                        $query->orWhere(function ($q) use ($r) {
                            $q->where('project_id', $r['project_id'])
                                ->where('batch_id', $r['batch_id'])
                                ->where('image_id', $r['image_id'])
                                ->where('date', $r['date']);
                        });
                    }
                }
            })
            ->delete();

        return response()->json([
            'message' => 'Cells deleted successfully',
            'deleted' => $deleted,
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
        $now = Carbon::now()->startOfDay();
        $toAnchor = Carbon::parse($to)->startOfDay();
        $anchorDate = $now->lessThanOrEqualTo($toAnchor) ? $now : $toAnchor;
        $anchorDate = Carbon::now()->startOfDay();

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

    /**
     * Возвращает ячейки за период + подсчитывает дилей и создает ячейки Delay для просроченных задач
     */
    public function delayByImageAndPeriod(int $projectId, int $batchId, int $imageId, Request $request): JsonResponse
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
        $to = Carbon::parse($request->input('to'))->startOfDay();
        $today = Carbon::now()->startOfDay();
        $yesterday = (clone $today)->subDay();

        // 1) Найти статус Delay
        $delayStatus = Status::query()->where('name', 'Delay')->first();
        if (!$delayStatus) {
            return $this->byImageAndPeriod($projectId, $batchId, $imageId, $request);
        }else{
            return response()->json([
                'message' => 'Status "Delay" not found',
            ], 404);
        }

        // Область выборки: захватим до вчерашнего дня, чтобы корректно посчитать дилей
        $queryTo = $to->greaterThan($yesterday) ? $to : $yesterday;

        $cells = ColoredCell::query()
            ->where('project_id', $projectId)
            ->where('batch_id', $batchId)
            ->where('image_id', $imageId)
            ->whereBetween('date', [$from, $queryTo])
            ->orderBy('date')
            ->get();

        if ($cells->isEmpty()) {
            return response()->json([
                'cells' => [],
            ]);
        }

        // 2) Найти группы подряд идущих дат с одинаковым статусом
        $groups = [];
        $currentGroup = null;
        foreach ($cells as $cell) {
            $cellDate = Carbon::parse($cell->date)->startOfDay();
            if ($currentGroup === null) {
                $currentGroup = [
                    'status_id' => $cell->status_id,
                    'start' => $cellDate->copy(),
                    'end' => $cellDate->copy(),
                    'completed_all' => (bool) $cell->completed,
                    'any_incomplete' => !(bool) $cell->completed,
                ];
                continue;
            }

            $prevEnd = $currentGroup['end'];
            $isConsecutive = $prevEnd->copy()->addDay()->equalTo($cellDate);
            $sameStatus = $currentGroup['status_id'] === $cell->status_id;

            if ($isConsecutive && $sameStatus) {
                $currentGroup['end'] = $cellDate->copy();
                $currentGroup['completed_all'] = $currentGroup['completed_all'] && (bool) $cell->completed;
                $currentGroup['any_incomplete'] = $currentGroup['any_incomplete'] || !(bool) $cell->completed;
            } else {
                $groups[] = $currentGroup;
                $currentGroup = [
                    'status_id' => $cell->status_id,
                    'start' => $cellDate->copy(),
                    'end' => $cellDate->copy(),
                    'completed_all' => (bool) $cell->completed,
                    'any_incomplete' => !(bool) $cell->completed,
                ];
            }
        }
        if ($currentGroup !== null) {
            $groups[] = $currentGroup;
        }

        // 3) Берём самую раннюю незавершенную группу, которая уже в прошлом (закончилась до сегодня)
        $delayedGroup = null;
        foreach ($groups as $g) {
            if ($g['any_incomplete'] && $g['end']->lt($today)) {
                $delayedGroup = $g;
                break;
            }
        }

        // Если просрочек нет — вернуть как есть в пределах диапазона
        if ($delayedGroup === null) {
            $result = $cells
                ->filter(function ($c) use ($from, $to) {
                    $d = Carbon::parse($c->date)->startOfDay();
                    return $d->betweenIncluded($from, $to);
                })
                ->values();

            return response()->json([
                'cells' => $result,
            ]);
        }

        // Дилей: с дня, следующего за концом группы, по вчера (или верхнюю границу диапазона)
        $delayStart = $delayedGroup['end']->copy()->addDay();
        $delayEnd = $yesterday->lt($to) ? $yesterday : $to;

        if ($delayStart->gt($delayEnd)) {
            // Дилей ещё не начался в заданном диапазоне — вернуть как есть
            $result = $cells
                ->filter(function ($c) use ($from, $to) {
                    $d = Carbon::parse($c->date)->startOfDay();
                    return $d->betweenIncluded($from, $to);
                })
                ->values();

            return response()->json([
                'cells' => $result,
            ]);
        }

        // Найдём первую задачу после начала дилея, чтобы сдвинуть последующие группы
        $firstFollowingCell = $cells->first(function ($c) use ($delayStart) {
            $d = Carbon::parse($c->date)->startOfDay();
            return $d->gte($delayStart);
        });

        // Смещение, чтобы первая последующая задача начиналась сразу после конца дилея
        $offsetDays = 0;
        if ($firstFollowingCell) {
            $firstFollowingDate = Carbon::parse($firstFollowingCell->date)->startOfDay();
            $newStartAfterDelay = $delayEnd->copy()->addDay();
            if ($newStartAfterDelay->gt($firstFollowingDate)) {
                $offsetDays = $firstFollowingDate->diffInDays($newStartAfterDelay);
            }
        }

        // Сформируем результирующий набор:
        // - все ячейки до первой последующей — без изменений
        // - все ячейки после/включая первую последующую — со сдвигом на offsetDays
        // - виртуальные ячейки Delay на каждый день просрочки
        $result = [];

        foreach ($cells as $cell) {
            $cellDate = Carbon::parse($cell->date)->startOfDay();

            if ($firstFollowingCell && $cellDate->gte(Carbon::parse($firstFollowingCell->date)->startOfDay())) {
                $newDate = $cellDate->copy()->addDays($offsetDays);
                if ($newDate->betweenIncluded($from, $to)) {
                    $clone = new ColoredCell([
                        'project_id' => $cell->project_id,
                        'batch_id' => $cell->batch_id,
                        'image_id' => $cell->image_id,
                        'status_id' => $cell->status_id,
                        'date' => $newDate,
                        'completed' => (bool) $cell->completed,
                    ]);
                    $clone->created_at = $cell->created_at;
                    $clone->updated_at = $cell->updated_at;
                    $result[] = $clone;
                }
            } else {
                if ($cellDate->betweenIncluded($from, $to)) {
                    $result[] = $cell;
                }
            }
        }

        // Добавляем виртуальные Delay-ячейки в пределах требуемого диапазона
        $cursor = $delayStart->copy();
        while ($cursor->lte($delayEnd)) {
            if ($cursor->betweenIncluded($from, $to)) {
                $result[] = new ColoredCell([
                    'project_id' => $projectId,
                    'batch_id' => $batchId,
                    'image_id' => $imageId,
                    'status_id' => $delayStatus->id,
                    'date' => $cursor->copy(),
                    'completed' => false,
                ]);
            }
            $cursor->addDay();
        }

        // Отсортируем по дате
        usort($result, function ($a, $b) {
            $ad = Carbon::parse($a->date)->startOfDay();
            $bd = Carbon::parse($b->date)->startOfDay();
            if ($ad->eq($bd)) { return 0; }
            return $ad->lt($bd) ? -1 : 1;
        });

        return response()->json([
            'cells' => array_values($result),
        ]);
    }


}
