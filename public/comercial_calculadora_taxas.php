<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/lc_permissions_enhanced.php';

if (!lc_can_access_comercial()) {
    header('Location: index.php?page=dashboard&error=permission_denied');
    exit;
}

includeSidebar('Calculadora de taxas');
?>

<style>
.rate-page { max-width: 1180px; margin: 0 auto; padding: 2rem; }
.rate-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; margin-bottom: 1.5rem; }
.rate-title h1 { margin: 0 0 .4rem; color: #1e3a8a; font-size: 2rem; }
.rate-title p { margin: 0; color: #64748b; }
.rate-btn { border: 0; border-radius: 8px; padding: .85rem 1.2rem; font-weight: 800; cursor: pointer; background: #2563eb; color: #fff; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: .35rem; }
.rate-btn:hover { background: #1d4ed8; color: #fff; }
.rate-btn.secondary { background: #f8fafc; color: #1e3a8a; border: 1px solid #bfdbfe; }
.rate-btn.secondary:hover { background: #eff6ff; color: #1e3a8a; }
.rate-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; box-shadow: 0 2px 8px rgba(15, 23, 42, .08); padding: 1.5rem; }
.rate-brand { display: flex; align-items: center; gap: .75rem; margin-bottom: 1.25rem; color: #1e3a8a; font-weight: 900; letter-spacing: .02em; }
.rate-brand img { width: 44px; height: 44px; object-fit: contain; border-radius: 8px; border: 1px solid #e5e7eb; background: #fff; }
.rate-intro { margin: 0 0 1.25rem; color: #64748b; line-height: 1.45; }
.rate-form { display: grid; grid-template-columns: minmax(260px, 1fr) auto; align-items: end; gap: 1rem; margin-bottom: .85rem; }
.rate-field { display: flex; flex-direction: column; gap: .4rem; }
.rate-field label { font-weight: 700; color: #334155; font-size: .9rem; }
.rate-field input { border: 1px solid #cbd5e1; border-radius: 8px; padding: .78rem .85rem; font: inherit; outline: none; }
.rate-field input:focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37, 99, 235, .12); }
.rate-actions { display: flex; gap: .75rem; flex-wrap: wrap; margin: 1rem 0 0; }
.rate-alert { margin-top: 1rem; border-radius: 10px; padding: .85rem 1rem; background: #eff6ff; color: #1e3a8a; border: 1px solid #bfdbfe; font-size: .9rem; display: none; }
.rate-table-wrap { margin-top: 1.25rem; overflow-x: auto; border: 1px solid #e5e7eb; border-radius: 10px; }
.rate-table { width: 100%; border-collapse: collapse; min-width: 620px; }
.rate-table th { text-transform: uppercase; letter-spacing: .05em; font-size: .75rem; color: #334155; background: #f1f5f9; padding: .85rem 1rem; text-align: left; }
.rate-table td { padding: .85rem 1rem; border-top: 1px solid #eef2f7; color: #0f172a; }
.rate-table tbody tr:hover { background: #f8fafc; }
.rate-table .right { text-align: right; }
@media (max-width: 800px) {
    .rate-page { padding: 1rem; }
    .rate-header, .rate-form { grid-template-columns: 1fr; flex-direction: column; align-items: stretch; }
    .rate-title h1 { font-size: 1.65rem; }
    .rate-actions .rate-btn, .rate-header .rate-btn { width: 100%; }
}
@media print {
    .sidebar, .rate-header .rate-btn, .rate-actions { display: none !important; }
    .main-content, #pageContent, .rate-page { margin: 0 !important; padding: 0 !important; max-width: none !important; }
    .rate-card { box-shadow: none; border: 0; }
}
</style>

<div class="rate-page">
    <div class="rate-header">
        <div class="rate-title">
            <h1>Calculadora de taxas</h1>
            <p>Simule parcelamentos de 1 a 18x com taxa fixa de 1,2% por parcela.</p>
        </div>
        <a class="rate-btn secondary" href="index.php?page=comercial">Voltar</a>
    </div>

    <section class="rate-card">
        <div class="rate-brand">
            <img src="logo-smile.png" alt="Smile Eventos">
            <span>Grupo Smile Eventos</span>
        </div>

        <p class="rate-intro">
            Informe o valor líquido desejado para calcular o valor total a cobrar e o valor de cada parcela.
        </p>

        <div class="rate-form">
            <div class="rate-field">
                <label for="valorLiquido">Valor líquido desejado (R$)</label>
                <input id="valorLiquido" type="text" inputmode="decimal" placeholder="Ex.: 5.190,00" value="5.190,00">
            </div>
            <button class="rate-btn" type="button" id="btnCalcular">Calcular</button>
        </div>

        <div class="rate-actions">
            <button class="rate-btn secondary" type="button" id="btnCopiar">Copiar resumo</button>
            <button class="rate-btn secondary" type="button" id="btnJpeg">Baixar JPEG</button>
            <button class="rate-btn secondary" type="button" id="btnPrint">Imprimir / PDF</button>
        </div>

        <div class="rate-alert" id="rateAlert" role="status"></div>

        <div class="rate-table-wrap">
            <table class="rate-table">
                <thead>
                    <tr>
                        <th>Parcelas</th>
                        <th class="right">Valor total a cobrar</th>
                        <th class="right">Valor de cada parcela</th>
                    </tr>
                </thead>
                <tbody id="tbody"></tbody>
            </table>
        </div>
    </section>
</div>

<script>
(function() {
    const TAXA = 0.012;
    const MIN = 1;
    const MAX = 18;
    const fmtBRL = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
    const input = document.getElementById('valorLiquido');
    const tbody = document.getElementById('tbody');
    const alertBox = document.getElementById('rateAlert');

    function showAlert(message) {
        alertBox.textContent = message;
        alertBox.style.display = 'block';
        window.setTimeout(function() {
            alertBox.style.display = 'none';
        }, 3200);
    }

    function parseMoedaToNumber(value) {
        if (typeof value !== 'string') return 0;
        value = value.replace(/\s/g, '').replace(/[R$\u00A0]/g, '');
        value = value.replace(/\./g, '').replace(',', '.');
        const number = Number(value);
        return Number.isFinite(number) ? number : 0;
    }

    function formatInputMoeda(element) {
        const number = parseMoedaToNumber(element.value);
        element.value = number ? fmtBRL.format(number).replace('R$', '').trim() : '';
    }

    function roundMoney(value) {
        return Math.round(value * 100) / 100;
    }

    function calcular() {
        const liquido = parseMoedaToNumber(input.value);
        tbody.innerHTML = '';

        if (!liquido || liquido <= 0) {
            tbody.innerHTML = '<tr><td colspan="3" style="color:#64748b">Informe um valor líquido válido para gerar a simulação.</td></tr>';
            return;
        }

        for (let parcelas = MIN; parcelas <= MAX; parcelas++) {
            const total = liquido / (1 - parcelas * TAXA);
            const parcela = total / parcelas;
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${parcelas}x</td>
                <td class="right">${fmtBRL.format(roundMoney(total))}</td>
                <td class="right">${fmtBRL.format(roundMoney(parcela))}</td>
            `;
            tbody.appendChild(tr);
        }
    }

    function gerarResumoTexto() {
        const linhas = Array.from(document.querySelectorAll('#tbody tr'));
        if (!linhas.length) return 'Sem dados.';

        const out = ['*Simulação de Parcelamento* (taxa 1,2% por parcela)'];
        for (const tr of linhas) {
            const tds = tr.querySelectorAll('td');
            if (tds.length < 3) continue;
            const parcelas = tds[0].textContent.trim();
            const total = tds[1].textContent.trim();
            const parcela = tds[2].textContent.trim();
            out.push(`${parcelas}: total ${total} - ${parcela} cada`);
        }
        return out.join('\n');
    }

    async function copiarResumo() {
        const texto = gerarResumoTexto();
        try {
            await navigator.clipboard.writeText(texto);
        } catch (error) {
            const textarea = document.createElement('textarea');
            textarea.value = texto;
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            textarea.remove();
        }
        showAlert('Resumo copiado. Cole direto no WhatsApp.');
    }

    function baixarJpegTabela() {
        const liquido = input.value.trim() || '';
        const linhas = Array.from(document.querySelectorAll('#tbody tr'));
        if (!linhas.length) {
            showAlert('Calcule primeiro para gerar a tabela.');
            return;
        }

        const largura = 1200;
        const header = 120;
        const linhaAltura = 46;
        const margem = 40;
        const altura = header + linhas.length * linhaAltura + margem * 2 + 30;
        const canvas = document.createElement('canvas');
        canvas.width = largura;
        canvas.height = altura;
        const ctx = canvas.getContext('2d');

        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, largura, altura);

        ctx.fillStyle = '#0f172a';
        ctx.font = 'bold 32px system-ui, -apple-system, Segoe UI';
        ctx.fillText('Simulação de Parcelamento - 1 a 18x', margem, 60);

        ctx.font = '16px system-ui, -apple-system, Segoe UI';
        ctx.fillStyle = '#64748b';
        ctx.fillText(`Valor líquido: R$ ${liquido} - Taxa: 1,2%/parcela`, margem, 90);

        const col1 = margem;
        const col2 = largura / 2 - 80;
        const col3 = largura - margem - 220;

        ctx.fillStyle = '#334155';
        ctx.font = 'bold 14px system-ui, -apple-system, Segoe UI';
        ctx.fillText('Parcelas', col1, header);
        ctx.fillText('Valor total a cobrar', col2, header);
        ctx.fillText('Valor de cada parcela', col3, header);

        ctx.fillStyle = '#0f172a';
        ctx.font = '16px system-ui, -apple-system, Segoe UI';
        let y = header + 40;
        for (const tr of linhas) {
            const tds = tr.querySelectorAll('td');
            if (tds.length < 3) continue;
            ctx.fillText(tds[0].textContent.trim(), col1, y);
            ctx.fillText(tds[1].textContent.trim(), col2, y);
            ctx.fillText(tds[2].textContent.trim(), col3, y);
            y += linhaAltura;
        }

        const url = canvas.toDataURL('image/jpeg', 0.92);
        const link = document.createElement('a');
        link.href = url;
        link.download = 'simulacao-parcelamento.jpg';
        document.body.appendChild(link);
        link.click();
        link.remove();
    }

    input.addEventListener('blur', function(event) {
        formatInputMoeda(event.target);
    });
    document.getElementById('btnCalcular').addEventListener('click', calcular);
    document.getElementById('btnCopiar').addEventListener('click', copiarResumo);
    document.getElementById('btnJpeg').addEventListener('click', baixarJpegTabela);
    document.getElementById('btnPrint').addEventListener('click', function() {
        window.print();
    });

    formatInputMoeda(input);
    calcular();
})();
</script>

<?php endSidebar(); ?>
