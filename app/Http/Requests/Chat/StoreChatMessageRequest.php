<?php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;

class StoreChatMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $thread = $this->route('thread');

        return $thread && $this->user()->can('sendMessage', $thread);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'min:1', 'max:2000'],
            'client_request_id' => ['required', 'string', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'message.required' => 'A message is required.',
            'message.max' => 'Message must not exceed 2000 characters.',
            'client_request_id.required' => 'A unique request ID is required for idempotency.',
            'client_request_id.uuid' => 'client_request_id must be a valid UUID v4.',
        ];
    }
}
