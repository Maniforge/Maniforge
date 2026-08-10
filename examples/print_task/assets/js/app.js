(function ($) {
  'use strict';

  var DISPLAY_ID = 'calc-display';
  var pickerState = { pickers: [], activeKey: null };
  var offcanvasInstance = null;

  function getDisplay() {
    return document.getElementById(DISPLAY_ID);
  }

  function readCount() {
    var el = getDisplay();
    return el ? ((el.textContent || '').trim() || '0') : '0';
  }

  function writeCount(value) {
    var el = getDisplay();
    if (el) {
      el.textContent = value === '' ? '0' : value;
    }
  }

  function applyCalcKey(action, value) {
    var current = readCount();
    if (action === 'erase') {
      writeCount('0');
      return;
    }
    if (action === 'del') {
      writeCount(current.length <= 1 ? '0' : current.slice(0, -1));
      return;
    }
    if (action === 'add' && value !== '') {
      writeCount(current === '0' ? value : current + value);
    }
  }

  function getPickerByKey(key) {
    return pickerState.pickers.find(function (p) { return p.key === key; }) || null;
  }

  function initPickerData() {
    var node = document.getElementById('calc-picker-data');
    if (!node) {
      return;
    }
    try {
      var data = JSON.parse(node.textContent || '{}');
      pickerState.pickers = data.pickers || [];
    } catch (e) {
      pickerState.pickers = [];
    }

    pickerState.pickers.forEach(function (picker) {
      var input = document.getElementById('picker-' + picker.key + '-id');
      if (!input && picker.key === 'executors') {
        input = document.getElementById('picker-executors-id');
      }
      if (!input && picker.key === 'operation') {
        input = document.getElementById('picker-operation-id');
      }
      if (input && picker.selected_id) {
        input.value = String(picker.selected_id);
      }
    });
  }

  function getOffcanvas() {
    var el = document.getElementById('calcPickerOffcanvas');
    if (!el) {
      return null;
    }
    if (!offcanvasInstance) {
      offcanvasInstance = bootstrap.Offcanvas.getOrCreateInstance(el);
    }
    return offcanvasInstance;
  }

  function renderPickerOptions(picker) {
    var $list = $('#calc-picker-options');
    var $title = $('#calcPickerOffcanvasLabel');
    $list.empty();
    $title.text(picker.label);

    (picker.options || []).forEach(function (opt) {
      var isActive = String(opt.id) === String(picker.selected_id);
      var $item;

      if (picker.type === 'navigate' && opt.href) {
        $item = $('<a>', {
          href: opt.href,
          class: 'list-group-item list-group-item-action calc-picker-option py-3' + (isActive ? ' active' : ''),
          role: 'option',
          'aria-selected': isActive
        });
      } else {
        $item = $('<button>', {
          type: 'button',
          class: 'list-group-item list-group-item-action calc-picker-option py-3' + (isActive ? ' active' : ''),
          'data-option-id': opt.id,
          'data-option-label': opt.label,
          role: 'option',
          'aria-selected': isActive
        });
      }

      var $row = $('<div class="d-flex justify-content-between align-items-center gap-2">');
      $row.append($('<span class="fw-medium text-start">').text(opt.label));
      if (opt.subtitle) {
        $row.append($('<span class="small text-muted flex-shrink-0">').text(opt.subtitle));
      }
      if (isActive) {
        $row.append($('<i class="bi bi-check-lg text-success flex-shrink-0">'));
      }
      $item.append($row);
      $list.append($item);
    });

    if (!picker.options || picker.options.length === 0) {
      $list.append('<div class="list-group-item text-muted py-4 text-center">Нет вариантов</div>');
    }
  }

  function openPicker(key) {
    var picker = getPickerByKey(key);
    if (!picker) {
      return;
    }
    pickerState.activeKey = key;
    renderPickerOptions(picker);
    var oc = getOffcanvas();
    if (oc) {
      oc.show();
    }
  }

  function applySelection(key, id, label) {
    var picker = getPickerByKey(key);
    if (!picker) {
      return;
    }
    picker.selected_id = id;
    picker.selected_label = label;

    var $display = $('[data-picker-display="' + key + '"]');
    $display.text(label);

    var inputId = key === 'executors' ? 'picker-executors-id' : ('picker-' + key + '-id');
    var input = document.getElementById(inputId);
    if (input) {
      input.value = String(id);
    }

    var $btn = $('.calc-picker[data-picker-key="' + key + '"]');
    $btn.attr('data-selected-id', id);
  }

  function confirmQuantity() {
    var qty = readCount();
    if (qty === '0') {
      showToast('Введите количество больше нуля');
      return;
    }
    var wp = $('#picker-workplace-id').val();
    var op = $('#picker-operation-id').val();
    var sg = $('#picker-executors-id').val();
    alert(
      'Подтверждено: ' + qty + ' шт.\n' +
      'Участок: ' + $('[data-picker-display="workplace"]').text() + '\n' +
      'Операция: ' + $('[data-picker-display="operation"]').text() + '\n' +
      'Исполнители: ' + $('[data-picker-display="executors"]').text() + '\n' +
      '(заглушка; id: wp=' + wp + ', op=' + op + ', sg=' + sg + ')'
    );
  }

  function showToast(message) {
    var toast = document.createElement('div');
    toast.className = 'position-fixed top-50 start-50 translate-middle alert alert-warning shadow px-4';
    toast.style.zIndex = '2000';
    toast.textContent = message;
    document.body.appendChild(toast);
    setTimeout(function () { toast.remove(); }, 2000);
  }

  function onCalcKeyClick(e) {
    var btn = e.target.closest('.calc-key');
    if (!btn) {
      return;
    }
    e.preventDefault();
    e.stopPropagation();
    var action = btn.getAttribute('data-calc-action');
    var value = btn.getAttribute('data-calc-value') || '';
    if (action) {
      applyCalcKey(action, value);
    }
  }

  $(document).on('click', '.calc-picker', function () {
    openPicker($(this).data('picker-key'));
  });

  $(document).on('click', '#calc-picker-options .calc-picker-option', function (e) {
    var key = pickerState.activeKey;
    if (!key || $(this).is('a')) {
      return;
    }
    e.preventDefault();
    var id = $(this).data('option-id');
    var label = $(this).data('option-label');
    applySelection(key, id, label);
    var oc = getOffcanvas();
    if (oc) {
      oc.hide();
    }
  });

  document.addEventListener('click', onCalcKeyClick, false);

  $(document).on('click', '#calc-confirm, #calc-confirm-ajax', function (e) {
    e.preventDefault();
    confirmQuantity();
  });

  $(document).on('click', '.task-order-info', function (e) {
    e.preventDefault();
    e.stopPropagation();
    openOrderModal($(this).data('order-nomer'));
  });

  function openOrderModal(orderNomer) {
    $.post('/api/order-info.php', { post_data: orderNomer })
      .done(function (res) {
        if (res.error) {
          alert(res.error);
          return;
        }
        $('#order-modal-root').html(res.html);
        var modalEl = document.getElementById('orderInfoModal');
        if (modalEl) {
          bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }
      })
      .fail(function () {
        alert('Ошибка загрузки заказа');
      });
  }

  $(function () {
    initPickerData();
  });

  window.PrintTask = { openOrderModal: openOrderModal };
})(jQuery);
