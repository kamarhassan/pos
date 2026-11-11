<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Patient;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

        $frameTableAvailable = Schema::hasTable('patient_frames');

        return view('backend.patients.create', compact('products', 'frameTableAvailable'));
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
        $frames = $this->preparePatientFrames($data);
        $sellFrame = $this->shouldSellFrame($request);
        $saleData = $sellFrame ? $this->validatedFrameSale($request) : [];

        [$patient, $order] = DB::transaction(function () use ($data, $frames, $sellFrame, $saleData) {
            $patient = new Patient();
            $patient->fill($data);
            $patient->save();
            $this->syncPatientFrames($patient, $frames);
            $this->syncLegacyCustomer($patient);

            $order = $sellFrame ? $this->recordPatientFrameSale($patient, $frames, $saleData) : null;

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
        $frameTableAvailable = Schema::hasTable('patient_frames');
        if ($frameTableAvailable) {
            $patient->load('frames');
            $selectedProductIds = $patient->frames->pluck('product_id');
        } else {
            $selectedProductIds = collect();
        }
        $selectedProductIds = $selectedProductIds
            ->push($patient->frame_product_id)
            ->filter()
            ->unique()
            ->values();
        $products = Product::where(function ($query) use ($selectedProductIds) {
            $query->where(function ($activeProducts) {
                $activeProducts->where('status', 1)->where('quantity', '>', 0);
            });

            if ($selectedProductIds->isNotEmpty()) {
                $query->orWhereIn('id', $selectedProductIds);
            }
        })
            ->orderBy('name')
            ->get();

        return view('backend.patients.edit', compact('patient', 'products', 'frameTableAvailable'));
    }

    public function update(Request $request, string $id)
    {
        abort_if(!auth()->user()->can('customer_update'), 403);
        $patient = Patient::findOrFail($id);
        $data = $this->validatedPatient($request);
        $frames = $this->preparePatientFrames($data);
        $sellFrame = $this->shouldSellFrame($request);
        $saleData = $sellFrame ? $this->validatedFrameSale($request) : [];

        $order = DB::transaction(function () use ($patient, $data, $frames, $sellFrame, $saleData) {
            $patient = Patient::whereKey($patient->id)->lockForUpdate()->firstOrFail();
            $patient->fill($data);
            $patient->save();
            $this->syncPatientFrames($patient, $frames);
            $this->syncLegacyCustomer($patient);

            return $sellFrame ? $this->recordPatientFrameSale($patient, $frames, $saleData) : null;
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

    private function preparePatientFrames(array &$data): array
    {
        $frameInputs = $data['frames'];
        unset($data['frames'], $data['frame_product_id'], $data['frame_chosen'], $data['frame_price'], $data['frame_quantity']);

        if (!Schema::hasTable('patient_frames') && count($frameInputs) > 1) {
            throw ValidationException::withMessages([
                'frames.1.product_id' => 'Only one frame can be saved until the patient_frames table is available.',
            ]);
        }

        $products = Product::whereIn('id', collect($frameInputs)->pluck('product_id'))
            ->get()
            ->keyBy('id');
        $frames = [];

        foreach ($frameInputs as $index => $frameInput) {
            $product = $products->get($frameInput['product_id']);
            if (!$product) {
                throw ValidationException::withMessages([
                    "frames.{$index}.product_id" => 'The selected frame no longer exists.',
                ]);
            }

            $frames[] = [
                'product_id' => $product->id,
                'frame_name' => $product->name,
                'frame_price' => $product->discounted_price,
                'quantity' => (int) $frameInput['quantity'],
            ];
        }

        $primaryFrame = $frames[0] ?? null;
        $data['frame_product_id'] = $primaryFrame['product_id'] ?? null;
        $data['frame_chosen'] = $primaryFrame['frame_name'] ?? null;
        $data['frame_price'] = $primaryFrame['frame_price'] ?? null;
        $data['frame_quantity'] = $primaryFrame['quantity'] ?? 0;

        return $frames;
    }

    private function syncPatientFrames(Patient $patient, array $frames): void
    {
        if (!Schema::hasTable('patient_frames')) {
            return;
        }

        $patient->frames()->delete();

        foreach ($frames as $frame) {
            $patient->frames()->create($frame);
        }
    }

    private function shouldSellFrame(Request $request): bool
    {
        return $request->input('patient_action') === 'sell_frame';
    }

    private function recordPatientFrameSale(Patient $patient, array $frames, array $data): Order
    {
        if (!$frames) {
            throw ValidationException::withMessages([
                'frames.0.product_id' => 'Select at least one frame before creating a sale.',
            ]);
        }

        $productIds = collect($frames)->pluck('product_id')->unique()->sort()->values();
        $products = Product::whereIn('id', $productIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        $saleLines = [];
        $saleTotal = 0;
        $subTotal = 0;

        foreach ($frames as $index => $frame) {
            $product = $products->get($frame['product_id']);
            if (!$product || !$product->status) {
                throw ValidationException::withMessages([
                    "frames.{$index}.product_id" => 'The selected frame is no longer available for sale.',
                ]);
            }

            $quantity = (int) $frame['quantity'];
            if ($product->quantity < $quantity) {
                throw ValidationException::withMessages([
                    "frames.{$index}.quantity" => "Only {$product->quantity} unit(s) of this frame are available.",
                ]);
            }

            $lineSaleTotal = round((float) $product->discounted_price * $quantity, 2);
            $lineSubTotal = round((float) $product->price * $quantity, 2);
            $saleTotal += $lineSaleTotal;
            $subTotal += $lineSubTotal;
            $saleLines[] = [
                'product' => $product,
                'quantity' => $quantity,
                'sale_total' => $lineSaleTotal,
                'sub_total' => $lineSubTotal,
            ];
        }

        $saleTotal = round($saleTotal, 2);
        $subTotal = round($subTotal, 2);
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

        $allocatedTotal = 0;
        $lastLineIndex = count($saleLines) - 1;
        foreach ($saleLines as $index => $line) {
            $lineTotal = $index === $lastLineIndex
                ? round($total - $allocatedTotal, 2)
                : ($waiveRemaining
                    ? round($total * ($line['sale_total'] / $saleTotal), 2)
                    : $line['sale_total']);
            $allocatedTotal += $lineTotal;
            $lineDiscount = round($line['sub_total'] - $lineTotal, 2);
            $product = $line['product'];

            $order->products()->create([
                'product_id' => $product->id,
                'quantity' => $line['quantity'],
                'price' => $product->price,
                'purchase_price' => $product->purchase_price,
                'discount' => $lineDiscount,
                'sub_total' => $line['sub_total'],
                'total' => $lineTotal,
            ]);

            $product->decrement('quantity', $line['quantity']);
        }

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
        if (!$request->exists('frames')) {
            $frames = $request->filled('frame_product_id')
                ? [[
                    'product_id' => $request->input('frame_product_id'),
                    'quantity' => $request->input('frame_quantity', 1),
                ]]
                : [];
        } else {
            $frames = $request->input('frames');
            if (is_array($frames)) {
                $frames = array_values(array_filter($frames, function ($frame) {
                    return !is_array($frame) || filled($frame['product_id'] ?? null);
                }));
            }
        }
        $request->merge(['frames' => $frames]);

        return $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'medical_history' => ['nullable', 'string'],
            'prescription_details' => ['nullable', 'string'],
            'frame_product_id' => ['nullable', 'integer', 'exists:products,id'],
            'frame_chosen' => ['nullable', 'string', 'max:255'],
            'frame_price' => ['nullable', 'numeric', 'min:0'],
            'frame_quantity' => ['nullable', 'integer', 'min:0'],
            'frames' => ['present', 'array'],
            'frames.*' => ['array:product_id,quantity'],
            'frames.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'frames.*.quantity' => ['required', 'integer', 'min:1'],
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
