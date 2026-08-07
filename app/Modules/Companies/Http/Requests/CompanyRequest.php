<?php

declare(strict_types=1);

namespace App\Modules\Companies\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The keys `validated()` can return are exactly the rules above, and every
 * one of them is a column on the model this request feeds. Stating that
 * lets the analyser verify `Model::create($request->validated())` instead of
 * seeing an array of unknown strings — and it fails loudly if a rule is ever
 * added for a field the model does not have.
 *
 * @method array<'legal_name'|'brand_name'|'name_ckb'|'name_ar'|'name_en'|'slug'|'description_ckb'|'description_ar'|'description_en'|'specialties'|'languages'|'website'|'email'|'telegram_username'|'license_number'|'license_authority'|'license_expires_at', mixed> validated(string|null $key = null, mixed $default = null)
 */
final class CompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(
            $this->route('company') ? 'companies.update' : 'companies.create',
        ) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $id = $this->route('company')?->id;

        return [
            'legal_name' => ['required', 'string', 'max:191'],
            'brand_name' => ['nullable', 'string', 'max:191'],
            'name_ckb' => ['required', 'string', 'max:191'],
            'name_ar' => ['nullable', 'string', 'max:191'],
            'name_en' => ['nullable', 'string', 'max:191'],
            'slug' => ['required', 'string', 'max:191', 'regex:/^[\p{L}\p{N}\-]+$/u', 'unique:companies,slug'.($id ? ','.$id : '')],
            'description_ckb' => ['nullable', 'string', 'max:20000'],
            'description_ar' => ['nullable', 'string', 'max:20000'],
            'description_en' => ['nullable', 'string', 'max:20000'],
            'specialties' => ['nullable', 'array'],
            'specialties.*' => ['string', 'max:64'],
            'languages' => ['nullable', 'array'],
            'languages.*' => ['string', 'max:8'],
            'website' => ['nullable', 'url', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'telegram_username' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_]+$/'],
            'license_number' => ['nullable', 'string', 'max:64'],
            'license_authority' => ['nullable', 'string', 'max:191'],
            'license_expires_at' => ['nullable', 'date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $company = $this->route('company');

            // A licence that expired is not evidence of anything. Catching it
            // at entry means a verifier is never looking at a licence field
            // that lapsed two years ago while deciding whether to verify.
            $expiry = $this->input('license_expires_at');

            if ($expiry !== null && $expiry !== '' && strtotime((string) $expiry) < strtotime('today')) {
                $validator->errors()->add('license_expires_at', __('companies.errors.licence_expired'));
            }

            // A licence number without the authority that issued it cannot be
            // checked by anyone, so it is not evidence either.
            if (trim((string) $this->input('license_number')) !== ''
                && trim((string) $this->input('license_authority')) === '') {
                $validator->errors()->add('license_authority', __('companies.errors.authority_required'));
            }
        });
    }
}
