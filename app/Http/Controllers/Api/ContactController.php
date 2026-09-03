<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespondsWithServiceResult;
use App\Http\Requests\StoreContactRequest;
use App\Http\Resources\ContactResource;
use App\Models\ProjectContact;
use App\Models\Task;
use App\Services\Workspace\AddContactService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * JSON sibling of the Workspace\ContactController. Reuses the same FormRequests
 * (authorization), action services (ServiceResult), and JsonResources as the web
 * surface — the only difference is the response shape.
 */
class ContactController extends Controller
{
    use RespondsWithServiceResult;

    public function store(StoreContactRequest $request, Task $task, AddContactService $service): JsonResponse
    {
        $result = $service->execute(
            task: $task,
            userId: $request->user()->id,
            attributes: $request->validated(),
        );

        return $this->respondWithResult(
            $result,
            $result->data instanceof ProjectContact ? new ContactResource($result->data) : null,
            $result->success ? 201 : null,
        );
    }
}
