// La API se consume con una URL relativa: Nginx recibe /api/ y la reenvía al
// servicio backend por la red interna. El navegador nunca habla con el backend.
const API = '/api';

const GRUPOS = [
    { clave: 'vencida', titulo: 'Vencidas' },
    { clave: 'proxima', titulo: 'Vencen pronto' },
    { clave: 'en_tiempo', titulo: 'Más adelante' },
    { clave: 'completada', titulo: 'Completadas' },
];

const ESTADOS = [
    ['pendiente', 'Pendiente'],
    ['en_progreso', 'En progreso'],
    ['completada', 'Completada'],
];

const $ = (selector) => document.querySelector(selector);

const lista = $('#lista');
const formAlta = $('#form-alta');
const formEditar = $('#form-editar');
const dialogoEditar = $('#dialogo-editar');
const dialogoEliminar = $('#dialogo-eliminar');

let actividades = [];
let enEdicion = null;
let porEliminar = null;
let temporizadorAviso = null;

// ------------------------------------------------------------- utilidades

class ErrorApi extends Error {
    constructor(mensaje, detalles = {}) {
        super(mensaje);
        this.detalles = detalles;
    }
}

async function api(ruta, opciones = {}) {
    let respuesta;
    try {
        respuesta = await fetch(API + ruta, {
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            ...opciones,
        });
    } catch {
        throw new ErrorApi('No hay conexión con el servidor. Revisa que los contenedores estén activos.');
    }

    let datos = null;
    try {
        datos = await respuesta.json();
    } catch {
        // Nginx responde HTML cuando el backend está detenido (502/504)
    }

    if (!respuesta.ok) {
        const mensaje = datos?.error
            ?? `El servidor no pudo atender la petición (código ${respuesta.status}). Intenta de nuevo en unos segundos.`;
        throw new ErrorApi(mensaje, datos?.detalles);
    }
    return datos;
}

function avisar(mensaje, tipo = 'ok') {
    const aviso = $('#aviso');
    aviso.textContent = mensaje;
    aviso.dataset.tipo = tipo;
    aviso.hidden = false;
    clearTimeout(temporizadorAviso);
    temporizadorAviso = setTimeout(() => { aviso.hidden = true; }, tipo === 'error' ? 7000 : 4000);
}

// 'AAAA-MM-DD' se interpreta como fecha local para no desfasar un día por zona horaria
function comoFecha(texto) {
    const [anio, mes, dia] = texto.split('-').map(Number);
    return new Date(anio, mes - 1, dia);
}

function fechaLocalISO(fecha = new Date()) {
    const dos = (n) => String(n).padStart(2, '0');
    return `${fecha.getFullYear()}-${dos(fecha.getMonth() + 1)}-${dos(fecha.getDate())}`;
}

function textoPlazo({ vencimiento, dias_restantes: dias }) {
    if (vencimiento === 'completada') return 'Completada';
    if (dias === 0) return 'Vence hoy';
    if (dias === 1) return 'Vence mañana';
    if (dias === -1) return 'Venció ayer';
    if (dias < 0) return `Venció hace ${-dias} días`;
    return `Faltan ${dias} días`;
}

function crear(etiqueta, clase, texto) {
    const nodo = document.createElement(etiqueta);
    if (clase) nodo.className = clase;
    if (texto !== undefined) nodo.textContent = texto;
    return nodo;
}

function mostrarErrores(formulario, detalles = {}) {
    formulario.querySelectorAll('[data-error]').forEach((parrafo) => {
        const campo = formulario.elements[parrafo.dataset.error];
        const mensaje = detalles[parrafo.dataset.error] ?? '';
        parrafo.textContent = mensaje;
        campo.setAttribute('aria-invalid', mensaje ? 'true' : 'false');
    });
}

function validarFormulario(formulario) {
    const errores = {};
    const titulo = formulario.elements.titulo.value.trim();
    if (titulo.length < 3) errores.titulo = 'Escribe un título de al menos 3 caracteres.';
    if (!formulario.elements.fecha_limite.value) errores.fecha_limite = 'Elige la fecha límite.';
    mostrarErrores(formulario, errores);
    return Object.keys(errores).length === 0;
}

function datosDe(formulario) {
    return {
        titulo: formulario.elements.titulo.value.trim(),
        descripcion: formulario.elements.descripcion.value.trim(),
        fecha_limite: formulario.elements.fecha_limite.value,
    };
}

async function conBotonOcupado(boton, tarea) {
    boton.disabled = true;
    try {
        return await tarea();
    } finally {
        boton.disabled = false;
    }
}

// ------------------------------------------------------------------ vista

function pintarResumen() {
    const cuenta = (clave) => actividades.filter((a) => a.vencimiento === clave).length;
    const vencidas = cuenta('vencida');
    const proximas = cuenta('proxima');
    const abiertas = actividades.length - cuenta('completada');
    const plural = (n, uno, varios) => `${n} ${n === 1 ? uno : varios}`;

    let texto;
    if (actividades.length === 0) {
        texto = 'Tu agenda está vacía. Agrega la primera actividad.';
    } else if (abiertas === 0) {
        texto = 'Todo completado. No tienes actividades abiertas.';
    } else if (vencidas === 0 && proximas === 0) {
        texto = `Tienes ${plural(abiertas, 'actividad abierta', 'actividades abiertas')} y ninguna vence en los próximos días.`;
    } else {
        const partes = [];
        if (vencidas) partes.push(plural(vencidas, 'vencida', 'vencidas'));
        if (proximas) partes.push(plural(proximas, 'vence pronto', 'vencen pronto'));
        texto = `Tienes ${plural(abiertas, 'actividad abierta', 'actividades abiertas')}: ${partes.join(' y ')}.`;
    }
    $('#resumen').textContent = texto;
}

function pintarActividad(actividad) {
    const fecha = comoFecha(actividad.fecha_limite);
    const item = crear('li', 'actividad');
    item.dataset.vencimiento = actividad.vencimiento;

    const hoja = crear('time', 'hoja-fecha');
    hoja.dateTime = actividad.fecha_limite;
    hoja.title = fecha.toLocaleDateString('es-MX', { dateStyle: 'full' });
    hoja.append(
        crear('span', 'hoja-fecha__mes', fecha.toLocaleDateString('es-MX', { month: 'short' }).replace('.', '')),
        crear('span', 'hoja-fecha__dia', String(fecha.getDate())),
        crear('span', 'hoja-fecha__semana', fecha.toLocaleDateString('es-MX', { weekday: 'short' }).replace('.', '')),
    );

    const cuerpo = crear('div');
    cuerpo.append(crear('h4', 'actividad__titulo', actividad.titulo));
    if (actividad.descripcion) {
        cuerpo.append(crear('p', 'actividad__descripcion', actividad.descripcion));
    }
    cuerpo.append(crear('p', 'actividad__plazo', textoPlazo(actividad)));

    const acciones = crear('div', 'actividad__acciones');

    const etiqueta = crear('label', 'solo-lectores', `Estado de ${actividad.titulo}`);
    const selector = crear('select');
    selector.id = `estado-${actividad.id}`;
    etiqueta.htmlFor = selector.id;
    for (const [valor, nombre] of ESTADOS) {
        const opcion = new Option(nombre, valor, false, valor === actividad.estado);
        selector.append(opcion);
    }
    selector.addEventListener('change', () => cambiarEstado(actividad, selector));

    const editar = crear('button', 'boton boton--ligero boton--chico', 'Editar');
    editar.type = 'button';
    editar.addEventListener('click', () => abrirEdicion(actividad));

    const eliminar = crear('button', 'boton boton--ligero boton--chico', 'Eliminar');
    eliminar.type = 'button';
    eliminar.addEventListener('click', () => abrirEliminacion(actividad));

    acciones.append(etiqueta, selector, editar, eliminar);
    cuerpo.append(acciones);
    item.append(hoja, cuerpo);
    return item;
}

function pintarLista() {
    lista.replaceChildren();
    pintarResumen();

    if (actividades.length === 0) {
        const vacio = crear('div', 'vacio');
        vacio.append(
            crear('strong', '', 'Aún no hay actividades'),
            crear('p', '', 'Usa el formulario para agregar la primera, con su fecha límite.'),
        );
        lista.append(vacio);
        return;
    }

    for (const grupo of GRUPOS) {
        const delGrupo = actividades.filter((a) => a.vencimiento === grupo.clave);
        if (delGrupo.length === 0) continue;

        const seccion = crear('section', 'grupo');
        const titulo = crear('h3', 'grupo__titulo', grupo.titulo);
        titulo.append(crear('span', 'grupo__cuenta', String(delGrupo.length)));
        const elementos = crear('ul');
        elementos.append(...delGrupo.map(pintarActividad));
        seccion.append(titulo, elementos);
        lista.append(seccion);
    }
}

function pintarLectura({ origen, ttl, consultas }) {
    const fuente = origen === 'redis'
        ? `Lista leída de la caché de Redis (se renueva en ${ttl} s).`
        : 'Lista leída de MariaDB y guardada en la caché de Redis.';
    const contador = consultas === null ? '' : ` Consulta número ${consultas} del listado.`;
    $('#lectura').textContent = fuente + contador;
}

// --------------------------------------------------------------- acciones

async function cargar() {
    try {
        const datos = await api('/actividades');
        actividades = datos.actividades;
        pintarLista();
        pintarLectura(datos);
    } catch (error) {
        $('#resumen').textContent = 'No se pudo cargar la agenda.';
        avisar(error.message, 'error');
    }
}

async function cambiarEstado(actividad, selector) {
    selector.disabled = true;
    try {
        await api(`/actividades/${actividad.id}`, {
            method: 'PUT',
            body: JSON.stringify({ estado: selector.value }),
        });
        avisar(`«${actividad.titulo}» ahora está ${selector.selectedOptions[0].text.toLowerCase()}.`);
        await cargar();
    } catch (error) {
        selector.value = actividad.estado;
        selector.disabled = false;
        avisar(error.message, 'error');
    }
}

function abrirEdicion(actividad) {
    enEdicion = actividad;
    mostrarErrores(formEditar);
    formEditar.elements.titulo.value = actividad.titulo;
    formEditar.elements.descripcion.value = actividad.descripcion;
    formEditar.elements.fecha_limite.value = actividad.fecha_limite;
    dialogoEditar.showModal();
}

function abrirEliminacion(actividad) {
    porEliminar = actividad;
    $('#aviso').hidden = true;
    $('#texto-eliminar').textContent = `«${actividad.titulo}» se borrará de la agenda. No se puede deshacer.`;
    dialogoEliminar.showModal();
}

formAlta.addEventListener('submit', async (evento) => {
    evento.preventDefault();
    if (!validarFormulario(formAlta)) return;

    await conBotonOcupado(formAlta.querySelector('[type="submit"]'), async () => {
        try {
            const datos = await api('/actividades', { method: 'POST', body: JSON.stringify(datosDe(formAlta)) });
            formAlta.reset();
            formAlta.elements.fecha_limite.value = fechaLocalISO();
            avisar(datos.correo_enviado
                ? 'Actividad agregada. Se envió el correo de aviso.'
                : 'Actividad agregada, pero no se pudo enviar el correo de aviso.');
            await cargar();
        } catch (error) {
            mostrarErrores(formAlta, error.detalles);
            avisar(error.message, 'error');
        }
    });
});

formEditar.addEventListener('submit', async (evento) => {
    evento.preventDefault();
    if (!validarFormulario(formEditar)) return;

    await conBotonOcupado(formEditar.querySelector('[type="submit"]'), async () => {
        try {
            await api(`/actividades/${enEdicion.id}`, { method: 'PUT', body: JSON.stringify(datosDe(formEditar)) });
            dialogoEditar.close();
            avisar('Cambios guardados.');
            await cargar();
        } catch (error) {
            mostrarErrores(formEditar, error.detalles);
            avisar(error.message, 'error');
        }
    });
});

$('#confirmar-eliminar').addEventListener('click', async (evento) => {
    await conBotonOcupado(evento.currentTarget, async () => {
        try {
            await api(`/actividades/${porEliminar.id}`, { method: 'DELETE' });
            dialogoEliminar.close();
            avisar('Actividad eliminada.');
            await cargar();
        } catch (error) {
            dialogoEliminar.close();
            avisar(error.message, 'error');
        }
    });
});

document.querySelectorAll('[data-cerrar]').forEach((boton) => {
    boton.addEventListener('click', () => boton.closest('dialog').close());
});

$('#recargar').addEventListener('click', (evento) => conBotonOcupado(evento.currentTarget, cargar));

// ----------------------------------------------------------------- inicio

$('#hoy').textContent = new Date().toLocaleDateString('es-MX', {
    weekday: 'long', day: 'numeric', month: 'long', year: 'numeric',
});
formAlta.elements.fecha_limite.value = fechaLocalISO();
$('#enlace-mailpit').href = `${location.protocol}//${location.hostname}:8025`;

cargar();
