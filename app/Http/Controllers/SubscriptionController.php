<?php

namespace App\Http\Controllers;

use App\Models\Action;
use App\Models\Customer;
use App\Models\Payment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Who has paid, who has not, and what the month is worth — PANEL_DOC Section 9.
 *
 * The distinction this screen exists to keep is between a LICENCE and a
 * PAYMENT. A licence is what the shop is allowed to run on; a payment is money
 * that actually arrived. They come apart exactly when it matters — a licence
 * delivered before the money, or money taken for months not yet issued — and a
 * screen that conflates them would let a customer look settled because they can
 * still trade.
 *
 * So nothing here reads a licence. It is the payments table and the fee, and
 * the answer is arithmetic on those two alone.
 */
class SubscriptionController extends Controller
{
    public function index(Request $request): View
    {
        $owing = $request->query('show') === 'owing';

        $customers = Customer::query()
            // One aggregate instead of a query per row: paidUpTo() reads this
            // when it is there.
            ->withMax('payments', 'covers_to')
            ->with(['payments' => fn ($query) => $query->latest('paid_on')->latest('id')->limit(1)])
            ->when($owing, fn ($query) => $query->owing())
            ->orderBy('name')
            ->get();

        $live = $customers->filter(fn (Customer $customer) => $customer->isLive());

        return view('subscriptions.index', [
            'customers' => $customers,
            'owing' => $owing,
            'owingCount' => Customer::owing()->count(),
            'allCount' => Customer::count(),

            // Integer dinars throughout — PROJECT_DOC Section 2.
            'monthly' => (int) $live->sum('monthly_fee'),
            'outstanding' => (int) $live->sum(fn (Customer $customer) => $customer->owes()),
            'thisMonth' => (int) Payment::whereBetween('paid_on', [
                now()->startOfMonth()->toDateString(),
                now()->endOfMonth()->toDateString(),
            ])->sum('amount'),
            'lastMonth' => (int) Payment::whereBetween('paid_on', [
                now()->subMonthNoOverflow()->startOfMonth()->toDateString(),
                now()->subMonthNoOverflow()->endOfMonth()->toDateString(),
            ])->sum('amount'),
        ]);
    }

    /** Every payment a customer has ever made. */
    public function show(Customer $customer): View
    {
        return view('subscriptions.customer', [
            'customer' => $customer,
            'payments' => $customer->payments()->with('recordedBy')
                ->orderByDesc('covers_to')->orderByDesc('id')->get(),
            'removed' => $customer->payments()->onlyTrashed()->with('recordedBy')
                ->orderByDesc('covers_to')->get(),
            'paidUpTo' => $customer->paidUpTo(),
        ]);
    }

    public function store(Request $request, Customer $customer): RedirectResponse
    {
        $fields = $this->validated($request, $customer);

        $payment = $customer->payments()->create([...$fields, 'recorded_by' => auth()->id()]);

        Action::record('payment.recorded', $customer, [
            'amount' => $payment->amount,
            'covers' => $payment->covers_from->toDateString().' → '.$payment->covers_to->toDateString(),
            'method' => $payment->method,
        ]);

        return back()->with('success', sprintf(
            '%s IQD recorded. %s is paid up to %s.',
            number_format($payment->amount),
            $customer->name,
            $customer->fresh()->paidUpTo()?->toDateString() ?? '—',
        ));
    }

    /**
     * A correction — a figure typed wrong, or a period that was not what was
     * agreed. Logged from → to, because money that changes after the fact is
     * the thing somebody will want to reconstruct.
     */
    public function update(Request $request, Customer $customer, Payment $payment): RedirectResponse
    {
        abort_unless($payment->customer_id === $customer->id, 404);

        $fields = $this->validated($request, $customer);

        $was = $payment->only(['amount', 'covers_from', 'covers_to', 'method', 'reference', 'note']);

        $payment->update($fields);

        Action::record('payment.corrected', $customer, [
            'payment' => $payment->id,
            'from' => ['amount' => $was['amount'], 'covers_to' => (string) $was['covers_to']?->toDateString()],
            'to' => ['amount' => $payment->amount, 'covers_to' => $payment->covers_to->toDateString()],
        ]);

        return redirect()->route('subscriptions.show', $customer)->with('success', 'That payment is corrected.');
    }

    /**
     * Hidden, not gone — Section 5 soft-deletes payments.
     *
     * A payment that can vanish without trace is a payment somebody can deny
     * receiving. This one stops counting towards what they have paid, and stays
     * on the record with the name of whoever removed it.
     */
    public function destroy(Customer $customer, Payment $payment): RedirectResponse
    {
        abort_unless($payment->customer_id === $customer->id, 404);

        $payment->delete();

        Action::record('payment.removed', $customer, [
            'payment' => $payment->id,
            'amount' => $payment->amount,
            'covers' => $payment->covers_from->toDateString().' → '.$payment->covers_to->toDateString(),
        ]);

        return back()->with('warning', sprintf(
            '%s IQD removed. It no longer counts towards what they have paid, and it is still on record.',
            number_format($payment->amount),
        ));
    }

    public function restore(Customer $customer, int $payment): RedirectResponse
    {
        $found = $customer->payments()->onlyTrashed()->findOrFail($payment);
        $found->restore();

        Action::record('payment.restored', $customer, ['payment' => $found->id, 'amount' => $found->amount]);

        return back()->with('success', 'That payment counts again.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, Customer $customer): array
    {
        return $request->validate([
            // Whole dinars. There is no smaller unit in circulation, and a
            // decimal here is a rounding error in somebody's invoice.
            'amount' => ['required', 'integer', 'min:1', 'max:100000000'],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'covers_from' => ['required', 'date'],
            'covers_to' => ['required', 'date', 'after_or_equal:covers_from'],
            'method' => ['nullable', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
        ], [
            'paid_on.before_or_equal' => 'Money cannot have arrived in the future.',
            'covers_to.after_or_equal' => 'The period has to end after it starts.',
        ], [
            'covers_from' => 'period start',
            'covers_to' => 'period end',
        ]);
    }
}
