<?php

namespace App\Http\Requests;

use App\Models\PersonalTodo;
use App\Support\WorkspaceAccess;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A request about one private to-do (`{todo}`), which only its owner may
 * make (WorkspaceAccess::ownsPersonalTodo). To anyone else the to-do is not
 * there: the answer is the 404 a missing one gets, word for word, never a
 * 403. Deleting one needs nothing more; the update and the move add their
 * fields.
 */
class PersonalTodoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return WorkspaceAccess::ownsPersonalTodo($this->user(), $this->todo());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    public function todo(): PersonalTodo
    {
        $todo = $this->route('todo');

        return $todo instanceof PersonalTodo ? $todo : throw new ModelNotFoundException;
    }

    protected function failedAuthorization(): void
    {
        throw (new ModelNotFoundException)->setModel(PersonalTodo::class, [$this->todo()->getKey()]);
    }
}
