// Checkout: postage and total follow the chosen state, payment method and delivery or pickup. The server does the maths; this only shows it.
(function () {
  var form = document.querySelector('.co-form[data-shipping-url]');
  if (!form) return;
  var url = form.getAttribute('data-shipping-url');
  var shipping = document.getElementById('co-shipping');
  var total = document.getElementById('co-total');
  var placeTotal = document.getElementById('co-place-total');
  var bankTotal = document.getElementById('co-bank-total');
  var error = document.getElementById('co-shipping-error');
  var place = document.getElementById('co-place');
  var latest = 0;

  function field(name) {
    var el = form.querySelector('[name="' + name + '"]:checked') || form.querySelector('[name="' + name + '"]');
    return el ? el.value : '';
  }

  // Delivery shows the address; pickup shows the pickup point choice instead.
  function showFulfilment() {
    var pickup = field('fulfilment') === 'pickup';
    var address = form.querySelector('[data-address-block]');
    var choice = form.querySelector('[data-pickup-choice]');
    if (address) address.hidden = pickup;
    if (choice) choice.hidden = !pickup;
  }

  function refresh() {
    var mine = ++latest;
    var query = '?state=' + encodeURIComponent(field('state')) +
      '&payment_method=' + encodeURIComponent(field('payment_method')) +
      '&fulfilment=' + encodeURIComponent(field('fulfilment')) +
      '&pickup_point_id=' + encodeURIComponent(field('pickup_point_id'));
    fetch(url + query, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (mine !== latest) return; // a newer choice has already been asked for
        if (shipping) shipping.textContent = d.shipping;
        if (total) total.textContent = d.total;
        if (placeTotal) placeTotal.textContent = d.total;
        if (bankTotal) bankTotal.textContent = d.total;
        if (error) {
          error.textContent = d.error || '';
          error.hidden = !d.error;
        }
        if (place) place.disabled = !d.ok;
      })
      .catch(function () {
        if (error) { error.textContent = 'Could not work out the postage. Check your connection and try again.'; error.hidden = false; }
        if (place) place.disabled = true;
      });
  }

  form.addEventListener('change', function (e) {
    var n = e.target.name;
    if (n === 'fulfilment') showFulfilment();
    if (n === 'state' || n === 'payment_method' || n === 'fulfilment' || n === 'pickup_point_id') refresh();
  });
  showFulfilment();
})();
