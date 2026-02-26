const allResponses = $input.all();
const allEditais = [];
let totalAPI = 0, erros = 0;

for (const response of allResponses) {
  const data = response.json;
  const items = data.items || [];
  totalAPI += items.length;
  if (!items.length && data.error) { erros++; continue; }

  for (const item of items) {
    const urlParts = (item.item_url || '').split('/');
    const cnpj = urlParts[2] || '';
    const ano = urlParts[3] || '';
    const seq = urlParts[4] || '';

    allEditais.push({
      id_pncp: item.numero_controle_pncp || '',
      titulo: item.title || '',
      descricao: (item.description || '').substring(0, 500),
      orgao_nome: item.orgao_nome || '',
      uf: item.uf || '',
      municipio: item.municipio_nome || '',
      modalidade: item.modalidade_licitacao_nome || '',
      data_publicacao: item.data_publicacao_pncp || '',
      data_encerramento: item.data_fim_vigencia || '',
      link_pncp: `https://pncp.gov.br/app/editais/${item.id || ''}`,
      link_arquivos_api: cnpj ? `https://pncp.gov.br/pncp-api/v1/orgaos/${cnpj}/compras/${ano}/${seq}/arquivos` : '',
      cnpj_orgao: cnpj, ano_compra: ano, seq_compra: seq,
      valor_global: item.valor_global || null
    });
  }
}

// Deduplicar
const seen = new Map();
for (const e of allEditais) { if (e.id_pncp && !seen.has(e.id_pncp)) seen.set(e.id_pncp, e); }
const unique = Array.from(seen.values());
unique.sort((a, b) => (a.data_encerramento||'9999').localeCompare(b.data_encerramento||'9999'));

// Agrupar por UF
const byUF = {};
for (const item of unique) { const uf = item.uf||'OUTROS'; if (!byUF[uf]) byUF[uf]=[]; byUF[uf].push(item); }

function fmtDate(iso) {
  if (!iso) return 'N/I';
  try { const d=new Date(iso); return `${String(d.getDate()).padStart(2,'0')}/${String(d.getMonth()+1).padStart(2,'0')}/${d.getFullYear()} ${String(d.getHours()).padStart(2,'0')}:${String(d.getMinutes()).padStart(2,'0')}`; }
  catch(e) { return iso; }
}
function diasAte(iso) { if (!iso) return 999; return Math.ceil((new Date(iso)-new Date())/86400000); }

const hoje = new Date();
const hojeStr = fmtDate(hoje.toISOString()).split(' ')[0];

let html = `<div style="font-family:'Segoe UI',Arial,sans-serif;max-width:960px;margin:0 auto;background:#f8f9fa;padding:20px">`;
html += `<div style="background:#2c3e50;color:#fff;padding:20px 25px;border-radius:8px 8px 0 0">`;
html += `<h1 style="margin:0;font-size:22px">&#128203; Monitoramento PNCP &mdash; ${hojeStr}</h1>`;
html += `<p style="margin:8px 0 0;font-size:14px;opacity:.9">${unique.length} editais abertos &bull; ${Object.keys(byUF).length} UFs &bull; ${totalAPI - unique.length} duplicatas removidas</p></div>`;
html += `<div style="background:#fff;padding:20px 25px;border-radius:0 0 8px 8px;border:1px solid #dee2e6">`;

for (const uf of ['RJ','SP','MG','BA','PE','DF']) {
  const items = byUF[uf];
  if (!items || !items.length) continue;
  const urg = items.filter(i => diasAte(i.data_encerramento) <= 7).length;

  html += `<h2 style="color:#2980b9;border-bottom:2px solid #2980b9;padding-bottom:8px;margin-top:25px">&#128205; ${uf} &mdash; ${items.length} editais`;
  if (urg) html += ` <span style="background:#e74c3c;color:#fff;padding:2px 8px;border-radius:10px;font-size:12px;margin-left:10px">&#9888; ${urg} urgente(s)</span>`;
  html += `</h2><table border="0" cellpadding="10" cellspacing="0" style="border-collapse:collapse;width:100%;font-size:12px;margin-bottom:15px">`;
  html += `<tr style="background:#34495e;color:#fff"><th style="text-align:left;width:22%">Órgão</th><th style="text-align:left;width:33%">Objeto</th><th style="text-align:center;width:13%">Encerramento</th><th style="text-align:right;width:12%">Valor Est.</th><th style="text-align:center;width:20%">Ações</th></tr>`;

  for (let i=0; i<items.length; i++) {
    const item = items[i];
    const dias = diasAte(item.data_encerramento);
    const bg = i%2===0?'#fff':'#f8f9fa';
    const brd = dias<=3?'border-left:4px solid #e74c3c':dias<=7?'border-left:4px solid #f39c12':'border-left:4px solid transparent';
    const urgS = dias<=3?'color:#e74c3c;font-weight:bold':dias<=7?'color:#f39c12;font-weight:bold':'';
    const diasL = dias<=0?'(ENCERRADO)':dias===1?'(amanhã!)':dias<=7?`(${dias} dias)`:'';
    const val = item.valor_global ? `R$ ${Number(item.valor_global).toLocaleString('pt-BR',{minimumFractionDigits:2})}` : '<em>N/I</em>';

    html += `<tr style="background:${bg};${brd}">`;
    html += `<td style="padding:10px;vertical-align:top"><strong>${item.orgao_nome}</strong><br/><small style="color:#777">${item.municipio}/${item.uf}</small></td>`;
    html += `<td style="padding:10px;vertical-align:top"><strong>${item.titulo}</strong><br/><small style="color:#555">${item.descricao.substring(0,200)}${item.descricao.length>200?'...':''}</small></td>`;
    html += `<td style="padding:10px;text-align:center;vertical-align:top;${urgS}">${fmtDate(item.data_encerramento)}<br/><small>${diasL}</small></td>`;
    html += `<td style="padding:10px;text-align:right;vertical-align:top">${val}</td>`;
    html += `<td style="padding:10px;text-align:center;vertical-align:top"><a href="${item.link_pncp}" style="display:inline-block;background:#2980b9;color:#fff;padding:5px 12px;border-radius:4px;text-decoration:none;font-size:11px;margin:2px">Ver PNCP</a>`;
    if (item.link_arquivos_api) html += `<br/><a href="${item.link_arquivos_api}" style="display:inline-block;background:#27ae60;color:#fff;padding:5px 12px;border-radius:4px;text-decoration:none;font-size:11px;margin:2px">Documentos</a>`;
    html += `</td></tr>`;
  }
  html += `</table>`;
}

html += `<hr style="margin-top:30px;border:none;border-top:1px solid #dee2e6"/>`;
html += `<p style="font-size:10px;color:#999;margin-top:15px">Gerado por <strong>Sunyata Consulting</strong> &mdash; Monitoramento PNCP<br/>${new Date().toISOString()} &bull; ${allResponses.length} buscas &bull; ${erros} erros</p></div></div>`;

return [{ json: {
  success: true,
  stats: { total_buscas: allResponses.length, total_bruto: totalAPI, total_deduplicado: unique.length, duplicatas_removidas: totalAPI-unique.length, erros_api: erros, por_uf: Object.fromEntries(Object.entries(byUF).map(([k,v])=>[k,v.length])) },
  editais: unique,
  email: { to: 'flitaiff@gmail.com', subject: `[PNCP] ${unique.length} editais abertos — ${hojeStr}`, html: html },
  timestamp: new Date().toISOString()
}}];