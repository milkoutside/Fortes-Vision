<?php

namespace App\Http\Controllers;

use App\Models\ColoredCell;
use App\Models\Status;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

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

        $deleted = ColoredCell::query()
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
}
