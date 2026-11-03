<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\ComplaintChannel;
use App\Enums\ComplaintStatus;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UpdateComplaintRequest extends StoreComplaintRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'status' => ['required', Rule::enum(ComplaintStatus::class)],
            'responded_at' => ['nullable', 'date', 'before_or_equal:now', 'after_or_equal:received_at'],
            // The answer must reach the consumer on paper or another durable medium, so a phone call does not count.
            'response_channel' => [
                'nullable',
                'required_with:responded_at',
                Rule::in(array_map(fn (ComplaintChannel $channel): string => $channel->value, ComplaintChannel::durableMediumChannels())),
            ],
            'response_summary' => ['nullable', 'required_with:responded_at', 'string', 'max:10000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $status = ComplaintStatus::from((string) $this->input('status'));
                $hasResponse = filled($this->input('responded_at'));

                if ($status->isAnswered() && ! $hasResponse) {
                    $validator->errors()->add(
                        'responded_at',
                        'Podaj datę wysłania odpowiedzi klientowi, aby nadać status „'.$status->label().'”.'
                    );
                }

                if (! $status->isAnswered() && $hasResponse) {
                    $validator->errors()->add(
                        'status',
                        'Po wysłaniu odpowiedzi wybierz status z decyzją: uznana, uznana częściowo, odrzucona albo zamknięta.'
                    );
                }
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function complaintAttributes(): array
    {
        $respondedAt = $this->validated('responded_at');

        return [
            ...parent::complaintAttributes(),
            'status' => $this->validated('status'),
            'responded_at' => filled($respondedAt) ? Carbon::parse((string) $respondedAt) : null,
            'response_channel' => $this->validated('response_channel') ?: null,
            'response_summary' => $this->nullableString('response_summary'),
        ];
    }

    public function attributes(): array
    {
        return [
            ...parent::attributes(),
            'status' => 'status',
            'responded_at' => 'data odpowiedzi',
            'response_channel' => 'sposób przekazania odpowiedzi',
            'response_summary' => 'treść / streszczenie odpowiedzi',
        ];
    }
}
