<div class="card-body">
  <div class="row">
    <div class="mb-3 col-md-6">
      <label class="form-label">Full name <span class="text-danger">*</span></label>
      <input type="text" class="form-control" name="full_name" value="{{ old('full_name', $patient->full_name ?? '') }}" required>
    </div>
    <div class="mb-3 col-md-6">
      <label class="form-label">Phone</label>
      <input type="text" class="form-control" name="phone" maxlength="50" value="{{ old('phone', $patient->phone ?? '') }}">
    </div>
    <div class="mb-3 col-md-6">
      <label class="form-label">Email</label>
      <input type="email" class="form-control" name="email" maxlength="255" value="{{ old('email', $patient->email ?? '') }}">
    </div>
    <div class="mb-3 col-md-6">
      <label class="form-label">Medical history</label>
      <textarea class="form-control" name="medical_history" rows="2">{{ old('medical_history', $patient->medical_history ?? '') }}</textarea>
    </div>
    <div class="mb-3 col-md-6">
      <label class="form-label">Prescription details</label>
      <textarea class="form-control" name="prescription_details" rows="2">{{ old('prescription_details', $patient->prescription_details ?? '') }}</textarea>
    </div>
    @php
      if (!$patient) {
        $storedFrameRows = [];
      } elseif ($frameTableAvailable) {
        $storedFrameRows = $patient->frames->map(function ($frame) {
          return ['product_id' => $frame->product_id, 'quantity' => $frame->quantity, 'discount' => $frame->discount ?? 0];
        })->all();
      } else {
        $storedFrameRows = $patient->frame_product_id
          ? [['product_id' => $patient->frame_product_id, 'quantity' => max(1, $patient->frame_quantity), 'discount' => 0]]
          : [];
      }
      $frameRows = old('frames', $storedFrameRows ?: [[]]);
      $frameRows = $frameRows ?: [[]];
      if (!$frameTableAvailable) {
        $frameRows = array_slice($frameRows, 0, 1);
      }
    @endphp
    <div class="col-12">
      <label class="form-label">Frames</label>
      @unless ($frameTableAvailable)
        <div class="alert alert-warning">
          Multiple frame records are unavailable until the database migration is run. Only one frame can be saved for this patient.
        </div>
      @endunless
      <input type="hidden" name="frames_touched" id="frames_touched" value="0">
      <div id="frame-items" data-next-index="{{ count($frameRows) }}">
        @foreach ($frameRows as $index => $frameRow)
          <div class="row frame-row align-items-end">
            <div class="mb-3 col-md-5">
              <label class="form-label">Frame</label>
              <select class="form-control frame-product-select" name="frames[{{ $index }}][product_id]" style="width: 100%;">
                <option value="">Select a frame</option>
                @foreach ($products as $product)
                  <option value="{{ $product->id }}"
                    data-price="{{ number_format($product->discounted_price, 2, '.', '') }}"
                    @selected((string) ($frameRow['product_id'] ?? '') === (string) $product->id)>
                    {{ $product->name }} ({{ $product->sku }}) — {{ $product->quantity }} in stock
                  </option>
                @endforeach
              </select>
              @error("frames.$index.product_id")
                <span class="text-danger">{{ $message }}</span>
              @enderror
            </div>
            <div class="mb-3 col-md-2">
              <label class="form-label">Price</label>
              <input type="number" step="0.01" min="0" class="form-control frame-price" value="" readonly>
            </div>
            <div class="mb-3 col-md-2">
              <label class="form-label">Quantity</label>
              <input type="number" min="1" class="form-control frame-quantity" name="frames[{{ $index }}][quantity]" value="{{ $frameRow['quantity'] ?? 1 }}" required>
            </div>
            <div class="mb-3 col-md-2">
              <label class="form-label">Discount</label>
              <input type="number" step="0.01" min="0" class="form-control frame-discount" name="frames[{{ $index }}][discount]" value="{{ $frameRow['discount'] ?? 0 }}">
              @error("frames.$index.quantity")
                <span class="text-danger">{{ $message }}</span>
              @enderror
            </div>
            <div class="mb-3 col-md-1">
              <button type="button" class="btn btn-outline-danger remove-frame-row" aria-label="Remove frame">
                <i class="fas fa-trash"></i>
              </button>
            </div>
          </div>
        @endforeach
      </div>
      @if ($frameTableAvailable)
        <button type="button" class="btn btn-outline-primary btn-sm mb-3" id="add-frame-row">
          <i class="fas fa-plus"></i> Add another frame
        </button>
      @endif
    </div>

    @if ($frameTableAvailable)
      <template id="frame-row-template">
        <div class="row frame-row align-items-end">
          <div class="mb-3 col-md-5">
            <label class="form-label">Frame</label>
            <select class="form-control frame-product-select" name="frames[__INDEX__][product_id]" style="width: 100%;">
              <option value="">Select a frame</option>
              @foreach ($products as $product)
                <option value="{{ $product->id }}"
                  data-price="{{ number_format($product->discounted_price, 2, '.', '') }}">
                  {{ $product->name }} ({{ $product->sku }}) — {{ $product->quantity }} in stock
                </option>
              @endforeach
            </select>
          </div>
          <div class="mb-3 col-md-2">
            <label class="form-label">Price</label>
            <input type="number" step="0.01" min="0" class="form-control frame-price" value="" readonly>
          </div>
          <div class="mb-3 col-md-2">
            <label class="form-label">Quantity</label>
            <input type="number" min="1" class="form-control frame-quantity" name="frames[__INDEX__][quantity]" value="1" required>
          </div>
          <div class="mb-3 col-md-2">
            <label class="form-label">Discount</label>
            <input type="number" step="0.01" min="0" class="form-control frame-discount" name="frames[__INDEX__][discount]" value="0">
          </div>
          <div class="mb-3 col-md-1">
            <button type="button" class="btn btn-outline-danger remove-frame-row" aria-label="Remove frame">
              <i class="fas fa-trash"></i>
            </button>
          </div>
        </div>
      </template>
    @endif

<style>
  .vision-measurements-table { min-width: 760px; }
  .vision-measurements-table th, .vision-measurements-table td { vertical-align: middle; }
  .vision-measurements-table input { min-width: 78px; }
  @media (max-width: 767.98px) {
    .frame-row > [class*="col-"], .lens-row > [class*="col-"] { flex: 0 0 100%; max-width: 100%; }
    .frame-row .btn, .lens-row .btn { width: 100%; }
    #frame-sale-summary { width: 100%; }
    .vision-measurements-table { font-size: 12px; }
    .vision-measurements-table th, .vision-measurements-table td { padding: .35rem; }
  }
</style>
    @push('script')
    <script>
      $(function() {
        const $frameItems = $('#frame-items');
        const $paid = $('#frame_paid');
        const $waiveRemaining = $('#waive_remaining');

        function updateSaleSummary() {
          let total = 0;
          $frameItems.find('.frame-row').each(function() {
            const select = this.querySelector('.frame-product-select');
            const option = select.options[select.selectedIndex];
            const price = option && option.value ? Number(option.dataset.price) : 0;
            const quantity = Number($(this).find('.frame-quantity').val() || 0);

            const discount = Math.min(Math.max(Number($(this).find('.frame-discount').val() || 0), 0), price * quantity);
            total += Math.max(price * quantity - discount, 0);
            $(this).find('.frame-price').val(option && option.value ? price.toFixed(2) : '');
          });

          let lensDiscountTotal = 0;
          $('#lens-items .lens-row').each(function() {
            const price = Number($(this).find('.lens-price').val() || 0);
            const quantity = Number($(this).find('.lens-quantity').val() || 0);
            const subtotal = price;
            const discount = Math.min(Math.max(Number($(this).find('.lens-discount').val() || 0), 0), subtotal);
            total += subtotal - discount;
            lensDiscountTotal += discount;

          });
          $('#lens_sale_discount').text(lensDiscountTotal.toFixed(2));
          $('#other-product-items .other-product-row').each(function() {
            const price = Number($(this).find('.other-product-price').val() || 0);
            const quantity = Number($(this).find('.other-product-quantity').val() || 1);
            const discount = Math.min(Math.max(Number($(this).find('.other-product-discount').val() || 0), 0), price * quantity);
            total += Math.max(price * quantity - discount, 0);
          });

          const paid = Number($paid.val() || 0);
          const waiveRemaining = $waiveRemaining.is(':checked');
          const balanceDiscount = waiveRemaining ? Math.max(total - paid, 0) : 0;
          const finalTotal = waiveRemaining ? Math.min(total, paid) : total;

          $('#frame_sale_total').text(finalTotal.toFixed(2));
          $('#frame_sale_discount').text(balanceDiscount.toFixed(2));
          $('#frame_sale_due').text(Math.max(finalTotal - paid, 0).toFixed(2));
        }

        function initializeFrameSelects($rows) {
          $rows.find('.frame-product-select').select2({
            theme: 'bootstrap4',
            placeholder: 'Search for a frame',
            allowClear: true
          });
        }

        initializeFrameSelects($frameItems);


        $frameItems.on('change', '.frame-product-select', function() {
          $('#frames_touched').val('1');
          updateSaleSummary();
        });

        $frameItems.on('input change', '.frame-quantity, .frame-discount', function() {
          $('#frames_touched').val('1');
          updateSaleSummary();
        });
        $frameItems.on('click', '.remove-frame-row', function() {
          $('#frames_touched').val('1');
          $(this).closest('.frame-row').remove();
          updateSaleSummary();
        });
        $('#add-frame-row').on('click', function() {
          $('#frames_touched').val('1');
          const index = Number($frameItems.attr('data-next-index'));
          $frameItems.attr('data-next-index', index + 1);
          const html = $('#frame-row-template').html().replace(/__INDEX__/g, index);
          const $row = $(html);
          $frameItems.append($row);
          initializeFrameSelects($row);
          updateSaleSummary();
        });

        $paid.add($waiveRemaining).on('input change', updateSaleSummary);
        $('#lens-items').on('input change', '.lens-price, .lens-quantity, .lens-discount', updateSaleSummary);
        $('#lens-items').on('click', '.remove-lens-row', function() { $(this).closest('.lens-row').remove(); updateSaleSummary(); });
        $('#add-lens-row').on('click', function() { const index = Number($('#lens-items').attr('data-next-index')); $('#lens-items').attr('data-next-index', index + 1); const $row = $($('#lens-row-template').html().replace(/__INDEX__/g, index)); $('#lens-items').append($row); updateSaleSummary(); });
        $('#other-product-items').on('input change', '.other-product-price, .other-product-quantity, .other-product-discount', updateSaleSummary);
        $('#other-product-items').on('click', '.remove-other-product-row', function() { $(this).closest('.other-product-row').remove(); updateSaleSummary(); });
        $('#add-other-product-row').on('click', function() { const index = Number($('#other-product-items').attr('data-next-index')); $('#other-product-items').attr('data-next-index', index + 1); const $row = $($('#other-product-row-template').html().replace(/__INDEX__/g, index)); $('#other-product-items').append($row); updateSaleSummary(); });
        updateSaleSummary();
      });
    </script>
    @endpush
    @php
      $storedLensRows = $patient && ($lensTableAvailable ?? false) && $patient->relationLoaded('lenses')
        ? $patient->lenses->map(fn ($lens) => ['lens_name' => $lens->lens_name, 'lens_price' => $lens->lens_price, 'quantity' => $lens->quantity, 'discount' => $lens->discount])->all()
        : [];
      $lensRows = old('lenses', $storedLensRows ?: [[]]);
    @endphp
    <div class="col-12">
      <label class="form-label">Lenses</label>
      <div id="lens-items" data-next-index="{{ count($lensRows) }}">
        @foreach ($lensRows as $index => $lensRow)
          <div class="row lens-row align-items-end">
            <div class="mb-3 col-md-5"><label class="form-label">Lens type</label><input type="text" maxlength="255" class="form-control lens-name" name="lenses[{{ $index }}][lens_name]" value="{{ $lensRow['lens_name'] ?? '' }}" placeholder="Enter lens type"></div>
            <div class="mb-3 col-md-2"><label class="form-label">Price</label><input type="number" step="0.01" min="0" class="form-control lens-price" name="lenses[{{ $index }}][lens_price]" value="{{ $lensRow['lens_price'] ?? '' }}"></div>
            <div class="mb-3 col-md-2"><label class="form-label">Quantity</label><input type="number" min="1" class="form-control lens-quantity" name="lenses[{{ $index }}][quantity]" value="{{ $lensRow['quantity'] ?? 2 }}"></div>
            <div class="mb-3 col-md-2"><label class="form-label">Discount</label><input type="number" step="0.01" min="0" class="form-control lens-discount" name="lenses[{{ $index }}][discount]" value="{{ $lensRow['discount'] ?? 0 }}"></div>
            <div class="mb-3 col-md-1"><button type="button" class="btn btn-outline-danger remove-lens-row"><i class="fas fa-trash"></i></button></div>
          </div>
        @endforeach
      </div>
      <button type="button" class="btn btn-outline-primary btn-sm mb-3" id="add-lens-row"><i class="fas fa-plus"></i> Add another lens</button>
    </div>
    <template id="lens-row-template"><div class="row lens-row align-items-end"><div class="mb-3 col-md-5"><label class="form-label">Lens type</label><input type="text" maxlength="255" class="form-control lens-name" name="lenses[__INDEX__][lens_name]" placeholder="Enter lens type"></div><div class="mb-3 col-md-2"><label class="form-label">Price</label><input type="number" step="0.01" min="0" class="form-control lens-price" name="lenses[__INDEX__][lens_price]"></div><div class="mb-3 col-md-2"><label class="form-label">Quantity</label><input type="number" min="1" class="form-control lens-quantity" name="lenses[__INDEX__][quantity]" value="2"></div><div class="mb-3 col-md-2"><label class="form-label">Discount</label><input type="number" step="0.01" min="0" class="form-control lens-discount" name="lenses[__INDEX__][discount]" value="0"></div><div class="mb-3 col-md-1"><button type="button" class="btn btn-outline-danger remove-lens-row"><i class="fas fa-trash"></i></button></div></div></template>

  @php
    $storedOtherProductRows = $patient && ($otherProductTableAvailable ?? false) && $patient->relationLoaded('otherProducts')
      ? $patient->otherProducts->map(fn ($item) => ['name' => $item->name, 'price' => $item->price, 'quantity' => $item->quantity, 'discount' => $item->discount])->all()
      : [];
    $otherProductRows = old('other_products', $storedOtherProductRows);
  @endphp
  <div class="col-12">
    <label class="form-label">OTHER PRODUCT</label>
    <div id="other-product-items" data-next-index="{{ count($otherProductRows) }}">
      @foreach ($otherProductRows as $index => $item)
        <div class="row other-product-row align-items-end">
          <div class="mb-3 col-md-5"><label class="form-label">Product name</label><input type="text" maxlength="255" class="form-control" name="other_products[{{ $index }}][name]" value="{{ $item['name'] ?? '' }}" placeholder="Enter product name"></div>
          <div class="mb-3 col-md-2"><label class="form-label">Price</label><input type="number" step="0.01" min="0" class="form-control other-product-price" name="other_products[{{ $index }}][price]" value="{{ $item['price'] ?? '' }}"></div>
          <div class="mb-3 col-md-2"><label class="form-label">Quantity</label><input type="number" min="1" class="form-control other-product-quantity" name="other_products[{{ $index }}][quantity]" value="{{ $item['quantity'] ?? 1 }}"></div>
          <div class="mb-3 col-md-2"><label class="form-label">Discount</label><input type="number" step="0.01" min="0" class="form-control other-product-discount" name="other_products[{{ $index }}][discount]" value="{{ $item['discount'] ?? 0 }}"></div>
          <div class="mb-3 col-md-1"><button type="button" class="btn btn-outline-danger remove-other-product-row"><i class="fas fa-trash"></i></button></div>
        </div>
      @endforeach
    </div>
    <button type="button" class="btn btn-outline-primary btn-sm mb-3" id="add-other-product-row"><i class="fas fa-plus"></i> Add OTHER PRODUCT</button>
  </div>
  <template id="other-product-row-template"><div class="row other-product-row align-items-end"><div class="mb-3 col-md-5"><label class="form-label">Product name</label><input type="text" maxlength="255" class="form-control" name="other_products[__INDEX__][name]" placeholder="Enter product name"></div><div class="mb-3 col-md-2"><label class="form-label">Price</label><input type="number" step="0.01" min="0" class="form-control other-product-price" name="other_products[__INDEX__][price]"></div><div class="mb-3 col-md-2"><label class="form-label">Quantity</label><input type="number" min="1" class="form-control other-product-quantity" name="other_products[__INDEX__][quantity]" value="1"></div><div class="mb-3 col-md-2"><label class="form-label">Discount</label><input type="number" step="0.01" min="0" class="form-control other-product-discount" name="other_products[__INDEX__][discount]" value="0"></div><div class="mb-3 col-md-1"><button type="button" class="btn btn-outline-danger remove-other-product-row"><i class="fas fa-trash"></i></button></div></div></template>

  <h5 class="mt-3">Vision measurements</h5>
  <div class="table-responsive">
    <table class="table table-bordered align-middle text-center vision-measurements-table">
      <thead class="table-light">
        <tr>
          <th>Eye</th>
          <th>SPH</th>
          <th>CYL</th>
          <th>AXIS</th>
          <th>ADD</th>
          <th>V.A</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <th>Right (O.D)<br><small>اليمنى</small></th>
          <td><input type="text" maxlength="50" class="form-control text-center" name="power_right_sphere" value="{{ old('power_right_sphere', $patient->power_right_sphere ?? '') }}"></td>
          <td><input type="text" maxlength="50" class="form-control text-center" name="power_right_cylinder" value="{{ old('power_right_cylinder', $patient->power_right_cylinder ?? '') }}"></td>
          <td><input type="text" maxlength="50" class="form-control text-center" name="power_right_axis" value="{{ old('power_right_axis', $patient->power_right_axis ?? '') }}"></td>
          <td><input type="text" maxlength="50" class="form-control text-center" name="power_right_addition" value="{{ old('power_right_addition', $patient->power_right_addition ?? '') }}"></td>
          <td><input type="text" maxlength="50" class="form-control text-center" name="power_right_va" value="{{ old('power_right_va', $patient->power_right_va ?? '') }}"></td>
        </tr>
        <tr>
          <th>Left (O.S)<br><small>اليسرى</small></th>
          <td><input type="text" maxlength="50" class="form-control text-center" name="power_left_sphere" value="{{ old('power_left_sphere', $patient->power_left_sphere ?? '') }}"></td>
          <td><input type="text" maxlength="50" class="form-control text-center" name="power_left_cylinder" value="{{ old('power_left_cylinder', $patient->power_left_cylinder ?? '') }}"></td>
          <td><input type="text" maxlength="50" class="form-control text-center" name="power_left_axis" value="{{ old('power_left_axis', $patient->power_left_axis ?? '') }}"></td>
          <td><input type="text" maxlength="50" class="form-control text-center" name="power_left_addition" value="{{ old('power_left_addition', $patient->power_left_addition ?? '') }}"></td>
          <td><input type="text" maxlength="50" class="form-control text-center" name="power_left_va" value="{{ old('power_left_va', $patient->power_left_va ?? '') }}"></td>
        </tr>
      </tbody>
    </table>
  </div>
  <div class="row g-3 align-items-center">
    <div class="col-md-3"><label class="form-label">PD (mm)</label><input type="text" maxlength="50" class="form-control" name="power_pd" value="{{ old('power_pd', $patient->power_pd ?? '') }}"></div>
    <div class="col-md-9 vision-options">
      @foreach ([
        'power_near' => 'Near',
        'power_dist' => 'Dist',
        'power_use' => 'Use',
        'power_progressive' => 'Progressive',
        'power_bifocal' => 'Bifocal',
      ] as $field => $label)
        <label class="form-check-label vision-option"><input type="checkbox" class="form-check-input" name="{{ $field }}" value="1" @checked(old($field, $patient->$field ?? false))> <span>{{ $label }}</span></label>
      @endforeach
    </div>
    <div class="col-12"><label class="form-label">Measurement notes</label><textarea class="form-control" name="power_notes" rows="2">{{ old('power_notes', $patient->power_notes ?? '') }}</textarea></div>
  </div>
    <div class="mb-3 col-md-6" id="frame-sale-summary">
      <label class="form-label">Initial payment</label>
      <input type="number" step="0.01" min="0" class="form-control" id="frame_paid" name="paid" value="{{ old('paid', $saleOrder->paid ?? 0) }}">
      @error('paid')
        <span class="text-danger">{{ $message }}</span>
      @enderror
      <div class="form-check mt-2">
        <input type="checkbox" class="form-check-input" id="waive_remaining" name="waive_remaining" value="1" @checked(old('waive_remaining'))>
        <label class="form-check-label" for="waive_remaining">Settle in full and discount the remaining balance</label>
      </div>
      @error('waive_remaining')
        <span class="text-danger">{{ $message }}</span>
      @enderror
      <small class="form-text text-muted">
        Sale total: <span id="frame_sale_total">0.00</span>
        | Balance discount: <span id="frame_sale_discount">0.00</span>
        | Lens discount: <span id="lens_sale_discount">0.00</span>
        | Remaining: <span id="frame_sale_due">0.00</span>
      </small>
    </div>

  <div class="patient-form-actions">
    <button type="submit" name="patient_action" value="save" class="btn bg-gradient-primary patient-save-button">{{ $submitLabel }}</button>
    <button type="submit" name="patient_action" value="sell_frame" id="sell-frame-button" class="btn btn-success patient-save-button">
      Create or update sale
    </button>
  </div>
</div>

<style>
  .vision-options {
    display: grid;
    grid-template-columns: repeat(3, minmax(120px, 1fr));
    gap: 10px;
    align-items: stretch;
    padding-top: 1.5rem;
  }
  .vision-option {
    display: flex;
    align-items: center;
    gap: 8px;
    min-height: 42px;
    margin: 0;
    padding: 8px 10px;
    border: 1px solid #dee2e6;
    border-radius: 6px;
    white-space: nowrap;
    line-height: 1.2;
  }
  .vision-option .form-check-input {
    position: static;
    flex: 0 0 auto;
    margin: 0;
  }
  @media (max-width: 767.98px) {
    .vision-options {
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 8px;
      padding-top: 0;
    }
    .vision-option {
      min-width: 0;
      padding: 8px 6px;
      font-size: 13px;
    }
  }
</style>

<style>
  .vision-measurements-table { direction: rtl; }
  .vision-measurements-table th, .vision-measurements-table td { text-align: center; }
</style>

<style>
  .patient-form-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 12px;
    width: 100%;
    margin-top: 20px;
    clear: both;
  }
  .patient-save-button {
    min-height: 42px;
    padding: 10px 20px;
    white-space: normal;
    line-height: 1.3;
  }
  @media (max-width: 767.98px) {
    .patient-form-actions {
      flex-direction: column;
      align-items: stretch;
    }
    .patient-save-button {
      width: 100%;
    }
  }
</style>
