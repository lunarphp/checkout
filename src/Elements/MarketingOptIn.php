<?php

namespace Lunar\Checkout\Elements;

/**
 * The customer's "email me offers" tick: the box in the contact step and on
 * the express confirm page. Unticked by default, as consent has to be.
 *
 * Opt-in like every element: register it and both boxes appear and persist
 * through the element bag; register nothing and neither renders, rather than
 * offering a box that goes nowhere. The package records the choice and does
 * nothing else with it. Which mailing list it feeds, and whether the
 * customer then confirms by email, is the host's call: read
 * `getElementData('marketing')['opt_in']` from the session on OrderPlacing.
 *
 * Its own region, which no layout slot renders, because the checkbox lives
 * inside the contact and express sections rather than as a step of its own.
 */
class MarketingOptIn extends AbstractCheckoutElement
{
    public function handle(): string
    {
        return 'marketing';
    }

    public function title(): string
    {
        return 'Marketing';
    }

    public function component(): string
    {
        return 'marketing-opt-in';
    }

    public function region(): string
    {
        return 'inline';
    }

    /**
     * @return array{label: string}
     */
    public function props(): array
    {
        return ['label' => (string) config('lunar.checkout.marketing.label', 'Email me with offers and new products.')];
    }

    public function rules(): array
    {
        return [
            'opt_in' => ['required', 'boolean'],
        ];
    }

    /**
     * Stored as a real boolean whatever the form posted ("1", "true", true).
     *
     * @param  array{opt_in: bool|int|string}  $data
     */
    public function store(array $data): void
    {
        parent::store(['opt_in' => filter_var($data['opt_in'], FILTER_VALIDATE_BOOLEAN)]);
    }
}
