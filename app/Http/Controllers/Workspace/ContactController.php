<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Workspace\Concerns\RedirectsWithServiceResult;
use App\Http\Requests\StoreContactRequest;
use App\Models\Task;
use App\Services\Workspace\AddContactService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;

class ContactController extends Controller
{
    use RedirectsWithServiceResult;

    public function store(StoreContactRequest $request, Task $task, AddContactService $service): RedirectResponse
    {
        $result = $service->execute(
            task: $task,
            userId: $request->user()->id,
            attributes: $request->validated(),
        );

        return $this->redirectWithResult($result);
    }
}
