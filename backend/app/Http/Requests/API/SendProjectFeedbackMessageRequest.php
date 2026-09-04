<?php
declare(strict_types=1);
namespace App\Http\Requests\API;
use Illuminate\Foundation\Http\FormRequest;
final class SendProjectFeedbackMessageRequest extends FormRequest {
    public function authorize(): bool { return true; }
    protected function prepareForValidation(): void { if(!$this->filled('client_request_id')&&$this->hasHeader('Idempotency-Key'))$this->merge(['client_request_id'=>$this->header('Idempotency-Key')]); }
    public function rules(): array { return ['message'=>'nullable|string|max:2000|required_without:attachment_ids','attachment_ids'=>'nullable|array|max:5|required_without:message','attachment_ids.*'=>'uuid|distinct','client_request_id'=>'required|string|min:8|max:100']; }
}
