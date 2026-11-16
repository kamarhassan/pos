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
        $lensTableAvailable = Schema::hasTable('patient_lenses');
        $otherProductTableAvailable = Schema::hasTable('patient_other_products');

        return view('backend.patients.create', compact('products', 'frameTableAvailable', 'lensTableAvailable', 'otherProductTableAvailable'));
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
        $lenses = $this->preparePatientLenses($data);
        $otherProducts = $this->preparePatientOtherProducts($data);
        $sellFrame = $this->shouldSellFrame($request);
        $saleData = $sellFrame ? $this->validatedFrameSale($request) : [];

        [$patient, $order] = DB::transaction(function () use ($data, $frames, $lenses, $otherProducts, $sellFrame, $saleData) {
            $patient = new Patient();
            $patient->fill($data);
            $patient->save();
            $this->syncPatientFrames($patient, $frames);
            $this->syncPatientLenses($patient, $lenses);
            $this->syncPatientOtherProducts($patient, $otherProducts);
            $this->syncLegacyCustomer($patient);

            $order = $sellFrame ? $this->recordPatientFrameSale($patient, $frames, $lenses, $otherProducts, $saleData) : null;

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
        $lensTableAvailable = Schema::hasTable('patient_lenses');
        $otherProductTableAvailable = Schema::hasTable('patient_other_products');
        if ($frameTableAvailable) {
            $patient->load('frames');
            $selectedProductIds = $patient->frames->pluck('product_id');
        } else {
            $selectedProductIds = collect();
        }
        if ($lensTableAvailable) {
            $patient->load('lenses');
            $selectedProductIds = $selectedProductIds->merge($patient->lenses->pluck('product_id'));
        }
        $selectedProductIds = $selectedProductIds
            ->push($patient->frame_product_id)
            ->push($lensTableAvailable ? ($patient->lenses->first()->product_id ?? null) : null)
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

        $saleOrder = $patient->legacy_customer_id
            ? Order::where('customer_id', $patient->legacy_customer_id)
                ->where('note', 'Frame and lens sale from patient record ' . $patient->id)
                ->latest('id')
                ->first()
            : null;

        if ($otherProductTableAvailable) $patient->load('otherProducts');

        return view('backend.patients.edit', compact('patient', 'products', 'frameTableAvailable', 'lensTableAvailable', 'otherProductTableAvailable', 'saleOrder'));
    }

    public function update(Request $request, string $id)
    {
        abort_if(!auth()->user()->can('customer_update'), 403);
        $patient = Patient::findOrFail($id);
        $data = $this->validatedPatient($request);
        $frames = $this->preparePatientFrames($data);
        $lenses = $this->preparePatientLenses($data);
        $otherProducts = $this->preparePatientOtherProducts($data);
        $sellFrame = $this->shouldSellFrame($request);
        $saleData = $sellFrame ? $this->validatedFrameSale($request) : [];

        $order = DB::transaction(function () use ($patient, $data, $frames, $lenses, $otherProducts, $sellFrame, $saleData) {
            $patient = Patient::whereKey($patient->id)->lockForUpdate()->firstOrFail();
            $patient->fill($data);
            $patient->save();
            $this->syncPatientFrames($patient, $frames);
            $this->syncPatientLenses($patient, $lenses);
            $this->syncPatientOtherProducts($patient, $otherProducts);
            $this->syncLegacyCustomer($patient);

            return $sellFrame ? $this->recordPatientFrameSale($patient, $frames, $lenses, $otherProducts, $saleData) : null;
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
        $orders = Order::with(['products.product', 'lenses'])
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
                'discount' => min(max(round((float) ($frameInput['discount'] ?? 0), 2), 0), round((float) $product->discounted_price * (int) $frameInput['quantity'], 2)),
            ];
        }

        $primaryFrame = $frames[0] ?? null;
        $data['frame_product_id'] = $primaryFrame['product_id'] ?? null;
        $data['frame_chosen'] = $primaryFrame['frame_name'] ?? null;
        $data['frame_price'] = $primaryFrame['frame_price'] ?? null;
        $data['frame_quantity'] = $primaryFrame['quantity'] ?? 0;

        return $frames;
    }

    private function preparePatientOtherProducts(array &$data): array
    {
        $inputs = $data['other_products'] ?? [];
        unset($data['other_products']);
        $items = [];
        foreach ($inputs as $index => $input) {
            $name = trim((string) ($input['name'] ?? ''));
            if ($name === '') throw ValidationException::withMessages(["other_products.{$index}.name" => 'Enter the product name.']);
            $price = round((float) ($input['price'] ?? 0), 2);
            $quantity = max(1, (int) ($input['quantity'] ?? 1));
            $discount = min(max(round((float) ($input['discount'] ?? 0), 2), 0), $price);
            $items[] = compact('name', 'price', 'quantity', 'discount');
        }
        return $items;
    }

    private function syncPatientOtherProducts(Patient $patient, array $items): void
    {
        if (!Schema::hasTable('patient_other_products')) return;
        $patient->otherProducts()->delete();
        foreach ($items as $item) $patient->otherProducts()->create($item);
    }

    private function preparePatientLenses(array &$data): array
    {
        $inputs = $data['lenses'] ?? [];
        unset($data['lenses'], $data['lens_chosen'], $data['lens_price'], $data['lens_quantity']);
        $lenses = [];
        foreach ($inputs as $index => $input) {
            $name = trim((string) ($input['lens_name'] ?? ''));
            $price = round((float) ($input['lens_price'] ?? 0), 2);
            $quantity = (int) ($input['quantity'] ?? 2);
            if ($quantity < 1) $quantity = 2;
            if ($name === '') throw ValidationException::withMessages(["lenses.{$index}.lens_name" => 'Enter the lens name.']);
            $discount = min(max(round((float) ($input['discount'] ?? 0), 2), 0), $price);
            $lenses[] = ['product_id' => null, 'lens_name' => $name, 'lens_price' => $price, 'discount' => $discount, 'quantity' => $quantity];
        }
        $primary = $lenses[0] ?? null;
        $data['lens_chosen'] = $primary['lens_name'] ?? null;
        $data['lens_price'] = $primary['lens_price'] ?? null;
        $data['lens_quantity'] = $primary['quantity'] ?? 0;
        return $lenses;
    }

    private function syncPatientLenses(Patient $patient, array $lenses): void
    {
        if (!Schema::hasTable('patient_lenses')) return;
        $patient->lenses()->delete();
        foreach ($lenses as $lens) $patient->lenses()->create($lens);
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

    private function recordPatientFrameSale(Patient $patient, array $frames, array $lenses, array $otherProducts, array $data): Order
    {
        $customer = $patient->legacyCustomer()->firstOrFail();
        $framesTouched = (bool) ($data['frames_touched'] ?? false);
        $existingOrder = Order::where('customer_id', $customer->id)
            ->where('note', 'Frame and lens sale from patient record ' . $patient->id)
            ->latest('id')
            ->lockForUpdate()
            ->first();
        $existingFrameQuantities = $existingOrder
            ? $existingOrder->products->groupBy('product_id')->map(fn ($lines) => $lines->sum('quantity'))->all()
            : [];

        if (!$frames && $existingOrder && !$framesTouched) {
            $frames = $existingOrder->products->map(fn ($line) => [
                'product_id' => $line->product_id,
                'quantity' => $line->quantity,
            ])->values()->all();
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
            $availableQuantity = $product->quantity + ($existingFrameQuantities[$product->id] ?? 0);
            if ($availableQuantity < $quantity) {
                throw ValidationException::withMessages([
                    "frames.{$index}.quantity" => "Only {$product->quantity} unit(s) of this frame are available.",
                ]);
            }

            $lineSubTotal = round((float) $product->price * $quantity, 2);
            $frameSubtotal = round((float) $product->discounted_price * $quantity, 2);
            $frameDiscount = min(max(round((float) ($frame['discount'] ?? 0), 2), 0), $frameSubtotal);
            $lineSaleTotal = round($frameSubtotal - $frameDiscount, 2);
            $saleTotal += $lineSaleTotal;
            $subTotal += $lineSubTotal;
            $saleLines[] = [
                'product' => $product,
                'quantity' => $quantity,
                'sale_total' => $lineSaleTotal,
                'sub_total' => $lineSubTotal,
            ];
        }

        $lensSubTotal = collect($lenses)->sum(fn ($lens) => round($lens['lens_price'], 2));
        $lensDiscount = collect($lenses)->sum('discount');
        $lensSaleTotal = round($lensSubTotal - $lensDiscount, 2);
        $otherSubTotal = collect($otherProducts)->sum(fn ($item) => round($item['price'], 2));
        $otherDiscount = collect($otherProducts)->sum('discount');
        $otherSaleTotal = round($otherSubTotal - $otherDiscount, 2);
        $frameSaleTotal = $saleTotal;

        $saleTotal = round($frameSaleTotal + $lensSaleTotal + $otherSaleTotal, 2);
        $subTotal = round($subTotal + $lensSubTotal + $otherSubTotal, 2);
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
        if ($existingOrder) {
            foreach ($existingOrder->products as $oldLine) {
                Product::whereKey($oldLine->product_id)->increment('quantity', $oldLine->quantity);
            }
            $existingOrder->products()->delete();
            $existingOrder->lenses()->delete();
            $existingOrder->otherProducts()->delete();
            $existingOrder->transactions()->delete();
            $existingOrder->update([
                'user_id' => auth()->id(),
                'lens_name' => $lenses[0]['lens_name'] ?? null,
                'lens_price' => $lenses[0]['lens_price'] ?? 0,
                'lens_quantity' => collect($lenses)->sum('quantity'),
                'lens_discount' => $lensDiscount,
                'lens_total' => $lensSaleTotal,
                'sub_total' => $subTotal,
                'discount' => $discount,
                'total' => $total,
                'paid' => $paid,
                'change_amount' => 0,
                'due' => round($total - $paid, 2),
                'status' => $paid >= $total,
            ]);
            $order = $existingOrder;
        } else {
            $order = Order::create([
                'customer_id' => $customer->id,
                'user_id' => auth()->id(),
                'lens_name' => $lenses[0]['lens_name'] ?? null,
                'lens_price' => $lenses[0]['lens_price'] ?? 0,
                'lens_quantity' => collect($lenses)->sum('quantity'),
                'lens_discount' => $lensDiscount,
                'lens_total' => $lensSaleTotal,
                'sub_total' => $subTotal,
                'discount' => $discount,
                'total' => $total,
                'paid' => $paid,
                'change_amount' => 0,
                'due' => round($total - $paid, 2),
                'status' => $paid >= $total,
                'note' => 'Frame and lens sale from patient record ' . $patient->id,
            ]);
        }

        $lensTotal = $waiveRemaining && $saleTotal > 0 ? round($total * ($lensSaleTotal / $saleTotal), 2) : $lensSaleTotal;
        $otherTotal = $waiveRemaining && $saleTotal > 0 ? round($total * ($otherSaleTotal / $saleTotal), 2) : $otherSaleTotal;
        $frameTotal = round($total - $lensTotal - $otherTotal, 2);
        $allocatedTotal = 0;
        $lastLineIndex = count($saleLines) - 1;
        foreach ($saleLines as $index => $line) {
            $lineTotal = $index === $lastLineIndex
                ? round($frameTotal - $allocatedTotal, 2)
                : ($waiveRemaining && $frameSaleTotal > 0
                    ? round($frameTotal * ($line['sale_total'] / $frameSaleTotal), 2)
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

        foreach ($lenses as $lens) {
            $lineSubTotal = round($lens['lens_price'], 2);
            $lineTotal = $lensTotal > 0 && $lensSaleTotal > 0
                ? round($lensTotal * (($lineSubTotal - $lens['discount']) / $lensSaleTotal), 2)
                : round($lineSubTotal - $lens['discount'], 2);
            $order->lenses()->create([
                'product_id' => $lens['product_id'], 'lens_name' => $lens['lens_name'],
                'price' => $lens['lens_price'], 'quantity' => $lens['quantity'],
                'discount' => round($lineSubTotal - $lineTotal, 2),
                'sub_total' => $lineSubTotal, 'total' => $lineTotal,
            ]);
            if ($lens['product_id']) {
                Product::whereKey($lens['product_id'])->decrement('quantity', $lens['quantity']);
            }
        }

        foreach ($otherProducts as $item) {
            $lineSubTotal = round($item['price'], 2);
            $lineTotal = $otherTotal > 0 && $otherSaleTotal > 0
                ? round($otherTotal * (($lineSubTotal - $item['discount']) / $otherSaleTotal), 2)
                : round($lineSubTotal - $item['discount'], 2);
            $order->otherProducts()->create([
                'name' => $item['name'], 'price' => $item['price'], 'quantity' => $item['quantity'],
                'discount' => round($lineSubTotal - $lineTotal, 2), 'sub_total' => $lineSubTotal, 'total' => $lineTotal,
            ]);
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
        $lenses = $request->input('lenses', []);
        $lenses = is_array($lenses) ? array_values(array_filter($lenses, fn ($lens) => is_array($lens) && filled($lens['lens_name'] ?? null))) : [];
        $otherProducts = $request->input('other_products', []);
        $otherProducts = is_array($otherProducts) ? array_values(array_filter($otherProducts, fn ($item) => is_array($item) && filled($item['name'] ?? null))) : [];
        $request->merge(['frames' => $frames, 'lenses' => $lenses, 'other_products' => $otherProducts]);

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
            'frames.*' => ['array:product_id,quantity,discount'],
            'frames.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'frames.*.quantity' => ['required', 'integer', 'min:1'],
            'frames.*.discount' => ['nullable', 'numeric', 'min:0'],
            'lenses' => ['present', 'array'],
            'lenses.*' => ['array:lens_name,lens_price,quantity,discount'],
            'lenses.*.lens_name' => ['required', 'string', 'max:255'],
            'lenses.*.lens_price' => ['required', 'numeric', 'min:0'],
            'lenses.*.quantity' => ['nullable', 'integer', 'min:1'],
            'lenses.*.discount' => ['nullable', 'numeric', 'min:0'],
            'other_products' => ['present', 'array'],
            'other_products.*' => ['array:name,price,quantity,discount'],
            'other_products.*.name' => ['required', 'string', 'max:255'],
            'other_products.*.price' => ['required', 'numeric', 'min:0'],
            'other_products.*.quantity' => ['nullable', 'integer', 'min:1'],
            'other_products.*.discount' => ['nullable', 'numeric', 'min:0'],
            'lens_chosen' => ['nullable', 'string', 'max:255'],
            'lens_price' => ['nullable', 'numeric', 'min:0'],
            'lens_quantity' => ['nullable', 'integer', 'min:0'],
            'others_chosen' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'power_right_sphere' => ['nullable', 'string', 'max:50'],
            'power_right_cylinder' => ['nullable', 'string', 'max:50'],
            'power_right_axis' => ['nullable', 'string', 'max:50'],
            'power_right_addition' => ['nullable', 'string', 'max:50'],
            'power_right_va' => ['nullable', 'string', 'max:50'],
            'power_left_sphere' => ['nullable', 'string', 'max:50'],
            'power_left_cylinder' => ['nullable', 'string', 'max:50'],
            'power_left_axis' => ['nullable', 'string', 'max:50'],
            'power_left_addition' => ['nullable', 'string', 'max:50'],
            'power_left_va' => ['nullable', 'string', 'max:50'],
            'power_pd' => ['nullable', 'string', 'max:50'],
            'power_near' => ['nullable', 'boolean'],
            'power_dist' => ['nullable', 'boolean'],
            'power_use' => ['nullable', 'boolean'],
            'power_progressive' => ['nullable', 'boolean'],
            'power_bifocal' => ['nullable', 'boolean'],
            'power_notes' => ['nullable', 'string'],
        ]);
    }

    private function validatedFrameSale(Request $request): array
    {
        return $request->validate([
            'paid' => ['nullable', 'numeric', 'min:0'],
            'waive_remaining' => ['nullable', 'boolean'],
            'lens_discount' => ['nullable', 'numeric', 'min:0'],
            'frames_touched' => ['nullable', 'boolean'],
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
