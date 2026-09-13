<?php declare(strict_types=1);

namespace App\Livewire\Support;

use App\Enums\SupportRequestType;
use App\Mail\SupportRequestMail;
use App\Models\User;
use App\Support\UrlNormalizer;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Title;
use Livewire\Component;
use RuntimeException;
use SanderMuller\FluentValidation\Contracts\FluentRuleContract;
use SanderMuller\FluentValidation\FluentRule;
use SanderMuller\FluentValidation\HasFluentValidation;
use Throwable;

#[Title('Support')]
class SupportPage extends Component
{
    use HasFluentValidation;

    public string $requestType = SupportRequestType::Feedback->value;

    public string $shopUrl = '';

    public string $message = '';

    public bool $submitted = false;

    /**
     * @return array<string, FluentRuleContract>
     */
    public function rules(): array
    {
        return [
            'requestType' => FluentRule::string('Request type')
                ->required()
                ->in(array_column(SupportRequestType::cases(), 'value')),
            'shopUrl' => FluentRule::string('Shop URL')
                ->nullable()
                ->requiredIf('requestType', SupportRequestType::ShopRequest, SupportRequestType::ShopIssue)
                ->rule('url:http,https')
                ->max(2048),
            'message' => FluentRule::string('Message')
                ->required()
                ->between(10, 5000),
        ];
    }

    public function updatedRequestType(): void
    {
        $this->submitted = false;
        $this->shopUrl = '';
        $this->resetValidation();
    }

    public function submit(): void
    {
        $this->submitted = false;

        /** @var array{requestType: string, shopUrl: string|null, message: string} $validated */
        $validated = $this->validate();
        $requestType = SupportRequestType::from($validated['requestType']);
        $user = $this->user();
        $rateLimitKey = 'support-request:user:' . $user->id;

        if (RateLimiter::hit($rateLimitKey, 3600) > 5) {
            $this->addError('form', __('You have sent several messages recently. Please wait before sending another.'));

            return;
        }

        try {
            Mail::to($this->recipient())->send(new SupportRequestMail(
                user: $user,
                requestType: $requestType,
                message: trim($validated['message']),
                shopUrl: $requestType->requiresShopUrl() ? UrlNormalizer::normalize(trim((string) $validated['shopUrl'])) : null,
            ));
        } catch (Throwable $exception) {
            RateLimiter::decrement($rateLimitKey, 3600);
            report($exception);
            $this->addError('form', __('We could not send your message. Please try again in a moment.'));

            return;
        }

        $this->reset(['shopUrl', 'message']);
        $this->submitted = true;
        Flux::toast(variant: 'success', text: __('Message sent.'));
    }

    public function render(): View
    {
        return view('livewire.support.support-page', [
            'requestTypes' => SupportRequestType::cases(),
            'selectedRequestType' => SupportRequestType::tryFrom($this->requestType) ?? SupportRequestType::Feedback,
            'user' => $this->user(),
        ]);
    }

    private function user(): User
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            throw new RuntimeException('Support requests require an authenticated user.');
        }

        return $user;
    }

    private function recipient(): string
    {
        foreach ([config('site.contact_email'), config('dipcatch.admin.email')] as $email) {
            if (is_string($email) && filter_var(trim($email), FILTER_VALIDATE_EMAIL) !== false) {
                return trim($email);
            }
        }

        throw new RuntimeException('No support email recipient is configured.');
    }
}
