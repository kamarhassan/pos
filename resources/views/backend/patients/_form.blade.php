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
    <div class="mb-3 col-md-6">
      <label class="form-label">Frame chosen</label>
      <select class="form-control select2" style="width: 100%;" id="frame_product_id" name="frame_product_id">
        <option value="">Select a frame</option>
        @foreach ($products as $product)
          <option value="{{ $product->id }}"
            data-price="{{ number_format($product->discounted_price, 2, '.', '') }}"
            data-stock="{{ $product->quantity }}"
            @selected((string) old('frame_product_id', $patient->frame_product_id ?? '') === (string) $product->id)>
            {{ $product->name }} ({{ $product->sku }}) — {{ $product->quantity }} in stock
          </option>
        @endforeach
      </select>
      @error('frame_product_id')
        <span class="text-danger">{{ $message }}</span>
      @enderror
    </div>
    <div class="mb-3 col-md-3">
      <label class="form-label">Frame price</label>
      <input type="number" step="0.01" min="0" class="form-control" id="frame_price" name="frame_price" value="{{ old('frame_price', $patient->frame_price ?? '') }}" readonly>
    </div>
    <div class="mb-3 col-md-3">
      <label class="form-label">Frame quantity</label>
      <input type="number" min="1" class="form-control" id="frame_quantity" name="frame_quantity" value="{{ old('frame_quantity', ($patient->frame_quantity ?? 0) ?: 1) }}" required>
      @error('frame_quantity')
        <span class="text-danger">{{ $message }}</span>
      @enderror
    </div>
    <div class="mb-3 col-md-6" id="frame-sale-summary">
      <label class="form-label">Initial payment</label>
      <input type="number" step="0.01" min="0" class="form-control" id="frame_paid" name="paid" value="{{ old('paid', 0) }}">
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
        | Remaining: <span id="frame_sale_due">0.00</span>
      </small>
    </div>

    @push('script')
    <script>
      $(function() {
        const $frame = $('#frame_product_id');
        const $quantity = $('#frame_quantity');
        const $price = $('#frame_price');
        const $paid = $('#frame_paid');
        const $waiveRemaining = $('#waive_remaining');
        const $sellButton = $('#sell-frame-button');

        function updateSaleSummary() {
          const option = $frame[0].options[$frame[0].selectedIndex];
          const total = option && option.value
            ? Number(option.dataset.price) * Number($quantity.val() || 0)
            : 0;
          const paid = Number($paid.val() || 0);
          const waiveRemaining = $waiveRemaining.is(':checked');
          const balanceDiscount = waiveRemaining ? Math.max(total - paid, 0) : 0;
          const finalTotal = waiveRemaining ? Math.min(total, paid) : total;

          $('#frame_sale_total').text(finalTotal.toFixed(2));
          $('#frame_sale_discount').text(balanceDiscount.toFixed(2));
          $('#frame_sale_due').text(Math.max(finalTotal - paid, 0).toFixed(2));
          $sellButton.prop('disabled', !option || !option.value ||
            Number(option.dataset.stock) < Number($quantity.val() || 0) || paid > total ||
            (waiveRemaining && paid <= 0));
        }

        $frame.select2({ theme: 'bootstrap4', placeholder: 'Search for a frame', allowClear: true });

        $frame.on('change', function() {
          const option = this.options[this.selectedIndex];

          if (!option.value) {
            $price.val('');
            $quantity.val(1);
            updateSaleSummary();
            return;
          }

          $price.val(option.dataset.price);
          if (!$quantity.val() || Number($quantity.val()) < 1) {
            $quantity.val(1);
          }
          updateSaleSummary();
        });

        $quantity.add($paid).add($waiveRemaining).on('input change', updateSaleSummary);
        $frame.trigger('change');
      });
    </script>
    @endpush
    <div class="mb-3 col-md-6">
      <label class="form-label">Lens chosen</label>
      <input type="text" class="form-control" name="lens_chosen" value="{{ old('lens_chosen', $patient->lens_chosen ?? '') }}">
    </div>
    <div class="mb-3 col-md-3">
      <label class="form-label">Lens price</label>
      <input type="number" step="0.01" min="0" class="form-control" name="lens_price" value="{{ old('lens_price', $patient->lens_price ?? '') }}">
    </div>
    <div class="mb-3 col-md-3">
      <label class="form-label">Lens quantity</label>
      <input type="number" min="0" class="form-control" name="lens_quantity" value="{{ old('lens_quantity', $patient->lens_quantity ?? 0) }}" required>
    </div>
    <div class="mb-3 col-md-6">
      <label class="form-label">Other products</label>
      <input type="text" class="form-control" name="others_chosen" value="{{ old('others_chosen', $patient->others_chosen ?? '') }}">
    </div>
    <div class="mb-3 col-md-6">
      <label class="form-label">Notes</label>
      <textarea class="form-control" name="notes" rows="2">{{ old('notes', $patient->notes ?? '') }}</textarea>
    </div>
  </div>

  <h5 class="mt-3">Vision measurements</h5>
  <div class="row">
    @foreach ([
      'power_right_sphere' => 'Right sphere',
      'power_right_cylinder' => 'Right cylinder',
      'power_right_addition' => 'Right addition',
      'power_left_sphere' => 'Left sphere',
      'power_left_cylinder' => 'Left cylinder',
      'power_left_addition' => 'Left addition',
    ] as $field => $label)
    <div class="mb-3 col-md-4">
      <label class="form-label">{{ $label }}</label>
      <input type="text" maxlength="50" class="form-control" name="{{ $field }}" value="{{ old($field, $patient->$field ?? '') }}">
    </div>
    @endforeach
    <div class="mb-3 col-md-12">
      <label class="form-label">Measurement notes</label>
      <textarea class="form-control" name="power_notes" rows="2">{{ old('power_notes', $patient->power_notes ?? '') }}</textarea>
    </div>
  </div>
  <button type="submit" name="patient_action" value="save" class="btn bg-gradient-primary">{{ $submitLabel }}</button>
  <button type="submit" name="patient_action" value="sell_frame" id="sell-frame-button" class="btn btn-success">
    Save patient &amp; sell frame
  </button>
</div>
