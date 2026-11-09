<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Patient;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Yajra\DataTables\DataTables;

class PatientController extends Controller
{
    public function index(Request $request)
    {
        abort_if(!auth()->user()->can('customer_view'), 403);

        if ($request->ajax()) {
            return DataTables::of(Patient::latest()->get())
                ->addIndexColumn()
                ->addColumn('created_at', fn (Patient $patient) => $patient->created_at?->format('d M, Y'))
                ->addColumn('action', function (Patient $patient) {
                    $actions = '<div class="btn-group"><button type="button" class="btn bg-gradient-primary dropdown-toggle" data-toggle="dropdown">Action</button><div class="dropdown-menu">';

                    if (auth()->user()->can('customer_update')) {
                        $actions .= '<a class="dropdown-item" href="' . route('backend.admin.patients.edit', $patient->id) . '"><i class="fas fa-edit"></i> Edit</a>';
                    }

                    if (auth()->user()->can('customer_delete')) {
                        $actions .= '<form action="' . route('backend.admin.patients.destroy', $patient->id) . '" method="POST">'
                            . csrf_field() . method_field('DELETE')
                            . '<button type="submit" class="dropdown-item" onclick="return confirm(\'Are you sure?\')"><i class="fas fa-trash"></i> Delete</button></form>';
                    }

                    if (auth()->user()->can('customer_sales') && $patient->legacy_customer_id) {
                        $actions .= '<a class="dropdown-item" href="' . route('backend.admin.patients.orders', $patient->id) . '"><i class="fas fa-cart-plus"></i> Sales</a>';
                    }

                    return $actions . '</div></div>';
                })
                ->rawColumns(['action'])
                ->toJson();
        }

        return view('backend.patients.index');
    }

    public function create()
    {
        abort_if(!auth()->user()->can('customer_create'), 403);

        $products = Product::where('status', 1)
            ->where('quantity', '>', 0)
            ->orderBy('name')
            ->get();

        return view('backend.patients.create', compact('products'));
    }

    public function store(Request $request)
    {
        abort_if(!auth()->user()->can('customer_create'), 403);

        if ($request->wantsJson()) {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:255'],
            ]);

            $patient = DB::transaction(function () use ($data) {
                $patient = Patient::create(['full_name' => $data['name']]);
                $patient->legacy_customer_id = Customer::create(['name' => $patient->full_name])->id;
                $patient->save();

                return $patient;
            });

            return response()->json([
                'id' => $patient->legacy_customer_id,
                'name' => $patient->full_name,
            ]);
        }

        $data = $this->validatedPatient($request);
        $sellFrame = $this->shouldSellFrame($request);
        $saleData = $sellFrame ? $this->validatedFrameSale($request) : [];

        [$patient, $order] = DB::transaction(function () use ($data, $sellFrame, $saleData) {
            $patient = new Patient();
            $this->syncFrameDetails($data);
            $patient->fill($data);
            $patient->save();
            $this->syncLegacyCustomer($patient);

            $order = $sellFrame ? $this->recordPatientFrameSale($patient, $saleData) : null;

            return [$patient, $order];
        });

        return $order
            ? to_route('backend.admin.orders.invoice', $order->id)->with('success', 'Frame sale recorded. Any remaining balance can be collected later.')
            : to_route('backend.admin.patients.index')->with('success', 'Patient created successfully.');
    }

    public function edit(string $id)
    {
        abort_if(!auth()->user()->can('customer_update'), 403);
        $patient = Patient::findOrFail($id);
        $products = Product::where(function ($query) use ($patient) {
            $query->where(function ($activeProducts) {
                $activeProducts->where('status', 1)->where('quantity', '>', 0);
            })->orWhere('id', $patient->frame_product_id);
        })
            ->orderBy('name')
            ->get();

        return view('backend.patients.edit', compact('patient', 'products'));
    }

    public function update(Request $request, string $id)
    {
        abort_if(!auth()->user()->can('customer_update'), 403);
        $patient = Patient::findOrFail($id);
        $data = $this->validatedPatient($request);
        $sellFrame = $this->shouldSellFrame($request);
        $saleData = $sellFrame ? $this->validatedFrameSale($request) : [];

        $order = DB::transaction(function () use ($patient, $data, $sellFrame, $saleData) {
            $patient = Patient::whereKey($patient->id)->lockForUpdate()->firstOrFail();
            $this->syncFrameDetails($data);
            $patient->fill($data);
            $patient->save();
            $this->syncLegacyCustomer($patient);

            return $sellFrame ? $this->recordPatientFrameSale($patient, $saleData) : null;
        });

        return $order
            ? to_route('backend.admin.orders.invoice', $order->id)->with('success', 'Frame sale recorded. Any remaining balance can be collected later.')
            : to_route('backend.admin.patients.index')->with('success', 'Patient updated successfully.');
    }

    public function destroy(string $id)
    {
        abort_if(!auth()->user()->can('customer_delete'), 403);
        $patient = Patient::findOrFail($id);

        DB::transaction(function () use ($patient) {
            $patient = Patient::whereKey($patient->id)->lockForUpdate()->firstOrFail();
            $customer = $patient->legacyCustomer()->first();
            $patient->delete();

            if ($customer && !$customer->patients()->exists() && !$customer->orders()->exists()) {
                $customer->delete();
            }
        });

        return to_route('backend.admin.patients.index')->with('success', 'Patient deleted successfully.');
    }

    public function getCustomers(Request $request)
    {
        abort_unless($request->wantsJson(), 406);

        return response()->json(
            Patient::whereNotNull('legacy_customer_id')
                ->orderBy('full_name')
                ->get(['legacy_customer_id', 'full_name', 'phone'])
                ->map(fn (Patient $patient) => [
                    'id' => $patient->legacy_customer_id,
                    'name' => $patient->full_name,
                    'phone' => $patient->phone,
                ])
        );
    }

    public function orders(string $id)
    {
        abort_if(!auth()->user()->can('customer_sales'), 403);
        $patient = Patient::findOrFail($id);
        abort_if(!$patient->legacy_customer_id, 404);
        $orders = Order::with('products.product')
            ->where('customer_id', $patient->legacy_customer_id)
            ->latest()
            ->paginate(25);

        return view('backend.patients.orders', compact('patient', 'orders'));
    }

    private function syncFrameDetails(array &$data): void
    {
        $newProductId = $data['frame_product_id'] ?? null;

        if (!$newProductId) {
            $data['frame_product_id'] = null;
            $data['frame_chosen'] = null;
            $data['frame_price'] = null;
            $data['frame_quantity'] = 0;

            return;
        }

        $product = Product::find($newProductId);
        if (!$product) {
            throw ValidationException::withMessages([
                'frame_product_id' => 'The selected frame no longer exists.',
            ]);
        }

        $data['frame_quantity'] = max(1, (int) $data['frame_quantity']);
        $data['frame_chosen'] = $product->name;
        $data['frame_price'] = $product->discounted_price;
    }

    private function shouldSellFrame(Request $request): bool
    {
        return $request->input('patient_action') === 'sell_frame';
    }

    private function recordPatientFrameSale(Patient $patient, array $data): Order
    {
        $quantity = max(1, (int) $patient->frame_quantity);

        if (!$patient->frame_product_id) {
            throw ValidationException::withMessages([
                'frame_product_id' => 'Select a frame before creating a sale.',
            ]);
        }

        $product = Product::whereKey($patient->frame_product_id)->lockForUpdate()->first();
        if (!$product || !$product->status) {
            throw ValidationException::withMessages([
                'frame_product_id' => 'The selected frame is no longer available for sale.',
            ]);
        }

        if ($product->quantity < $quantity) {
            throw ValidationException::withMessages([
                'frame_quantity' => "Only {$product->quantity} unit(s) of this frame are available.",
            ]);
        }

        $unitPrice = (float) $product->discounted_price;
        $saleTotal = round($unitPrice * $quantity, 2);
        $paid = round((float) ($data['paid'] ?? 0), 2);
        $waiveRemaining = (bool) ($data['waive_remaining'] ?? false);
        if ($paid > $saleTotal) {
            throw ValidationException::withMessages([
                'paid' => 'The initial payment cannot exceed the sale total.',
            ]);
        }
        if ($waiveRemaining && $paid <= 0) {
            throw ValidationException::withMessages([
                'paid' => 'Enter a payment greater than zero to settle the sale with a discount.',
            ]);
        }
        $total = $waiveRemaining ? $paid : $saleTotal;

        $customer = $patient->legacyCustomer()->first();
        if (!$customer) {
            throw ValidationException::withMessages([
                'full_name' => 'The patient must have a linked customer record before creating a sale.',
            ]);
        }

        $subTotal = round((float) $product->price * $quantity, 2);
        $discount = round($subTotal - $total, 2);
        $order = Order::create([
            'customer_id' => $customer->id,
            'user_id' => auth()->id(),
            'sub_total' => $subTotal,
            'discount' => $discount,
            'total' => $total,
            'paid' => $paid,
            'change_amount' => 0,
            'due' => round($total - $paid, 2),
            'status' => $paid >= $total,
            'note' => 'Frame sale from patient record ' . $patient->id,
        ]);

        $order->products()->create([
            'product_id' => $product->id,
            'quantity' => $quantity,
            'price' => $product->price,
            'purchase_price' => $product->purchase_price,
            'discount' => $discount,
            'sub_total' => $subTotal,
            'total' => $total,
        ]);

        $product->decrement('quantity', $quantity);

        if ($paid > 0) {
            $order->transactions()->create([
                'amount' => $paid,
                'customer_id' => $customer->id,
                'user_id' => auth()->id(),
                'paid_by' => 'cash',
            ]);
        }

        return $order;
    }

    private function validatedPatient(Request $request): array
    {
        return $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'medical_history' => ['nullable', 'string'],
            'prescription_details' => ['nullable', 'string'],
            'frame_product_id' => ['nullable', 'integer', 'exists:products,id'],
            'frame_chosen' => ['nullable', 'string', 'max:255'],
            'frame_price' => ['nullable', 'numeric', 'min:0'],
            'frame_quantity' => ['required', 'integer', 'min:0'],
            'lens_chosen' => ['nullable', 'string', 'max:255'],
            'lens_price' => ['nullable', 'numeric', 'min:0'],
            'lens_quantity' => ['required', 'integer', 'min:0'],
            'others_chosen' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'power_right_sphere' => ['nullable', 'string', 'max:50'],
            'power_right_cylinder' => ['nullable', 'string', 'max:50'],
            'power_right_addition' => ['nullable', 'string', 'max:50'],
            'power_left_sphere' => ['nullable', 'string', 'max:50'],
            'power_left_cylinder' => ['nullable', 'string', 'max:50'],
            'power_left_addition' => ['nullable', 'string', 'max:50'],
            'power_notes' => ['nullable', 'string'],
        ]);
    }

    private function validatedFrameSale(Request $request): array
    {
        return $request->validate([
            'paid' => ['nullable', 'numeric', 'min:0'],
            'waive_remaining' => ['nullable', 'boolean'],
        ]);
    }

    private function syncLegacyCustomer(Patient $patient): void
    {
        $customer = $patient->legacyCustomer ?? new Customer();
        $phoneInUse = filled($patient->phone) && Customer::where('phone', $patient->phone)
            ->when($customer->exists, fn ($query) => $query->where('id', '!=', $customer->id))
            ->exists();

        $customer->name = $patient->full_name;
        $customer->phone = $phoneInUse ? null : $patient->phone;
        $customer->save();

        $patient->legacy_customer_id = $customer->id;
        $patient->save();
    }
}
