<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegistrationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email:strict,dns,spoof', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Password::min(8)
                                                    ->letters()
                                                    ->mixedCase()
                                                    ->numbers()
                                                    ->symbols()
                                                    ->uncompromised()],
            "api_key" => ["nullable","min:30"],
        ];
    }
}
