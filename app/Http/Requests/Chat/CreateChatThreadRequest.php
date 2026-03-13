<?php

namespace App\Http\Requests\Chat;

use App\Models\ChatThread;
use Illuminate\Foundation\Http\FormRequest;

class CreateChatThreadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', ChatThread::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:255'],
        ];
    }
}
