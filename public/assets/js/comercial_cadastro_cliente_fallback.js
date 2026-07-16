(function () {
    var hydrateAttempts = 0;

    function onlyDigits(value) {
        return String(value || '').replace(/\D/g, '');
    }

    function formatCpf(value) {
        var digits = onlyDigits(value).slice(0, 11);
        digits = digits.replace(/(\d{3})(\d)/, '$1.$2');
        digits = digits.replace(/(\d{3})(\d)/, '$1.$2');
        digits = digits.replace(/(\d{3})(\d{1,2})$/, '$1-$2');
        return digits;
    }

    function formatCnpj(value) {
        var digits = onlyDigits(value).slice(0, 14);
        digits = digits.replace(/(\d{2})(\d)/, '$1.$2');
        digits = digits.replace(/(\d{3})(\d)/, '$1.$2');
        digits = digits.replace(/(\d{3})(\d)/, '$1/$2');
        digits = digits.replace(/(\d{4})(\d{1,2})$/, '$1-$2');
        return digits;
    }

    function formatPhone(value) {
        var digits = onlyDigits(value).slice(0, 11);
        if (digits.length <= 10) {
            digits = digits.replace(/(\d{2})(\d)/, '($1) $2');
            digits = digits.replace(/(\d{4})(\d)/, '$1-$2');
        } else {
            digits = digits.replace(/(\d{2})(\d)/, '($1) $2');
            digits = digits.replace(/(\d{5})(\d)/, '$1-$2');
        }
        return digits;
    }

    function formatCep(value) {
        return onlyDigits(value).slice(0, 8).replace(/(\d{5})(\d)/, '$1-$2');
    }

    function syncDocumentoMask() {
        var tipo = document.getElementById('tipo_pessoa');
        var doc = document.getElementById('documento_numero');
        var label = document.getElementById('documentoLabel');
        if (!tipo || !doc || !label) return;
        if (tipo.value === 'PJ') {
            label.textContent = 'CNPJ';
            doc.value = formatCnpj(doc.value);
        } else {
            label.textContent = 'CPF';
            doc.value = formatCpf(doc.value);
        }
    }

    function setValue(id, value) {
        var field = document.getElementById(id);
        if (field) field.value = value || '';
    }

    function applyCliente(cliente, editId) {
        var form = document.getElementById('clienteForm');
        if (!form || form.querySelector('input[name="id"]')) return;

        var hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'id';
        hidden.value = cliente.id || editId;
        form.insertBefore(hidden, form.firstChild);

        setValue('tipo_pessoa', cliente.tipo_pessoa || 'PF');
        setValue('documento_numero', cliente.documento_numero);
        setValue('nome_completo', cliente.nome_completo);
        setValue('rg', cliente.rg);
        setValue('telefone_whatsapp', cliente.telefone_whatsapp);
        setValue('email', cliente.email);
        setValue('cep', cliente.cep);
        setValue('endereco_numero', cliente.endereco_numero);
        setValue('endereco_complemento', cliente.endereco_complemento);
        setValue('endereco_logradouro', cliente.endereco_logradouro);
        setValue('endereco_bairro', cliente.endereco_bairro);
        setValue('endereco_cidade', cliente.endereco_cidade);
        setValue('endereco_estado', cliente.endereco_estado);

        var pageTitle = document.querySelector('.cliente-title');
        var cardTitle = document.querySelector('.cliente-card-title');
        var cardSubtitle = document.querySelector('.cliente-card-subtitle');
        var cancelLink = document.querySelector('.cliente-actions .cliente-btn.secondary');
        var submitButton = document.querySelector('.cliente-actions .cliente-btn.primary');
        if (pageTitle) pageTitle.textContent = 'Editar cliente';
        if (cardTitle) cardTitle.textContent = 'Editar cadastro';
        if (cardSubtitle) cardSubtitle.textContent = 'Altere os dados necessários e salve o cadastro.';
        if (cancelLink) cancelLink.href = 'index.php?page=comercial_clientes_cadastrados';
        if (submitButton) submitButton.textContent = 'Salvar alterações';

        syncDocumentoMask();
        var phone = document.getElementById('telefone_whatsapp');
        var cep = document.getElementById('cep');
        if (phone) phone.value = formatPhone(phone.value);
        if (cep) cep.value = formatCep(cep.value);
        document.documentElement.setAttribute('data-cliente-fallback', 'applied');
    }

    function hydrate() {
        document.documentElement.setAttribute('data-cliente-fallback', 'started');
        hydrateAttempts += 1;

        var form = document.getElementById('clienteForm');
        if (!form) {
            document.documentElement.setAttribute('data-cliente-fallback-reason', 'no-form');
            if (hydrateAttempts < 12) {
                window.setTimeout(hydrate, 500);
            }
            return;
        }
        if (form.querySelector('input[name="id"]')) return;

        var match = String(window.location.href || '').match(/[?&#](?:edit_id|cliente_id|id)=([0-9]+)/);
        var editId = match ? match[1] : '';
        if (!editId || !/^\d+$/.test(editId)) {
            document.documentElement.setAttribute('data-cliente-fallback-reason', 'no-id');
            if (hydrateAttempts < 12) {
                window.setTimeout(hydrate, 500);
            }
            return;
        }

        var fallbackForm = document.createElement('form');
        fallbackForm.method = 'post';
        fallbackForm.action = 'index.php?page=comercial_cadastro_cliente';
        fallbackForm.style.display = 'none';
        fallbackForm.innerHTML = '<input type="hidden" name="action" value="open_cliente_edit"><input type="hidden" name="id" value="' + editId + '">';
        document.body.appendChild(fallbackForm);
        document.documentElement.setAttribute('data-cliente-fallback', 'posting');
        fallbackForm.submit();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', hydrate);
    } else {
        hydrate();
    }
})();
