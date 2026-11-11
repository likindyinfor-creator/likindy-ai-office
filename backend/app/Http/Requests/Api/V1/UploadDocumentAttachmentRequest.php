<?php

namespace App\Http\Requests\Api\V1;

use App\Services\DocumentAttachmentService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UploadDocumentAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'extensions:pdf,png,jpg,jpeg,txt',
                'mimetypes:application/pdf,image/png,image/jpeg,text/plain',
                'max:10240',
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $file = $this->file('file');

            if ($file !== null && ! DocumentAttachmentService::supports($file)) {
                $validator->errors()->add('file', 'The file extension and file content do not match.');
            }
        });
    }
}
