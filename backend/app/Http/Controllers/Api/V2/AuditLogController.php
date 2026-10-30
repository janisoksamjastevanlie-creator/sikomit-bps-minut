<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:100'],
            'subjectType' => ['nullable', 'string', 'max:100'],
            'subjectId' => ['nullable', 'string', 'max:80'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'offset' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ]);
        $query = AuditLog::query()->with('user');
        if (!empty($filters['action'])) $query->where('action', 'like', '%'.$filters['action'].'%');
        if (!empty($filters['subjectType'])) $query->where('subject_type', $filters['subjectType']);
        if (!empty($filters['subjectId'])) $query->where('subject_id', $filters['subjectId']);

        $limit = (int) ($filters['limit'] ?? 50);
        $offset = (int) ($filters['offset'] ?? 0);
        $total = (clone $query)->count();
        $items = $query->orderByDesc('occurred_at')->offset($offset)->limit($limit)->get();

        return response()->json([
            'items' => $items->map(fn (AuditLog $row) => [
                'id' => (string) $row->id,
                'userId' => $row->user_id ? (string) $row->user_id : '',
                'userName' => $row->user?->name ?? 'Sistem',
                'action' => $row->action,
                'subjectType' => $row->subject_type,
                'subjectId' => $row->subject_id,
                'before' => $row->before_values,
                'after' => $row->after_values,
                'occurredAt' => $row->occurred_at?->getTimestampMs() ?? 0,
            ])->values(),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }
}
