package id.go.bps.minut.manajemenmitra

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.FilterChip
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.lifecycle.viewmodel.compose.viewModel
import id.go.bps.minut.manajemenmitra.data.ActivitySummary
import id.go.bps.minut.manajemenmitra.data.ActivityTarget
import id.go.bps.minut.manajemenmitra.data.AssignmentSummary
import id.go.bps.minut.manajemenmitra.data.AssignmentWriteRequest
import id.go.bps.minut.manajemenmitra.data.PartnerOption

private val assignmentCategories = listOf(
    "PENDATAAN_LAPANGAN" to "Pendataan Lapangan",
    "PENGOLAHAN" to "Pengolahan",
    "PENDATAAN_DAN_PENGOLAHAN" to "Pendataan dan Pengolahan"
)

@Composable
fun AssignmentListScreen(
    role: String,
    onBack: () -> Unit,
    onCreate: () -> Unit,
    model: AssignmentListViewModel = viewModel()
) {
    val state by model.state.collectAsState()
    var query by rememberSaveable { mutableStateOf("") }
    val canCreate = role in setOf("ADMINISTRATOR_SISTEM", "PENGELOLA_KEGIATAN", "KOORDINATOR_LAPANGAN")
    LaunchedEffect(Unit) { model.load() }

    Column(Modifier.fillMaxSize().padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            TextButton(onClick = onBack) { Text("‹ Kembali") }
            Text("Penugasan", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
        }
        if (canCreate) Button(onClick = onCreate, modifier = Modifier.fillMaxWidth()) { Text("Buat penugasan") }
        OutlinedTextField(
            value = query,
            onValueChange = { query = it },
            label = { Text("Cari kegiatan, mitra, atau wilayah") },
            singleLine = true,
            enabled = !state.loading,
            modifier = Modifier.fillMaxWidth()
        )
        Button(onClick = { model.load(query) }, enabled = !state.loading, modifier = Modifier.fillMaxWidth()) { Text("Cari") }

        when {
            state.loading && state.items.isEmpty() -> Column(
                modifier = Modifier.fillMaxSize(), horizontalAlignment = Alignment.CenterHorizontally,
                verticalArrangement = Arrangement.Center
            ) { CircularProgressIndicator(); Spacer(Modifier.height(10.dp)); Text("Memuat penugasan…") }
            state.forbidden -> AssignmentMessage("Akses tidak diizinkan", state.error ?: "Tidak dapat melihat penugasan.")
            state.error != null -> Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                AssignmentMessage("Penugasan gagal dimuat", state.error ?: "Terjadi kesalahan.")
                Button(onClick = { model.load(query) }) { Text("Coba lagi") }
            }
            state.loaded && state.items.isEmpty() -> AssignmentMessage("Belum ada penugasan", "Penugasan akan muncul setelah dibuat oleh pengelola kegiatan.")
            else -> {
                Text("${state.items.size} dari ${state.total} penugasan", style = MaterialTheme.typography.labelMedium)
                LazyColumn(modifier = Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    items(state.items, key = { it.id }) { assignment -> AssignmentCard(assignment) }
                }
            }
        }
    }
}

@Composable
private fun AssignmentCard(assignment: AssignmentSummary) {
    Card(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Text(assignment.kegiatanNama.ifBlank { "Kegiatan" }, fontWeight = FontWeight.Bold)
            Text("${assignment.kegiatanKode} · ${assignment.mitraNama} · ${assignment.status}")
            if (assignment.contractCategory.isNotBlank()) Text(labelContractCategory(assignment.contractCategory), style = MaterialTheme.typography.bodySmall)
            Text(assignment.uraian, style = MaterialTheme.typography.bodySmall)
            val dates = listOf(assignment.jadwalMulai, assignment.jadwalSelesai).filter(String::isNotBlank).joinToString(" – ")
            val location = listOf(assignment.wilayah, dates).filter(String::isNotBlank).joinToString(" · ")
            if (location.isNotBlank()) Text(location, style = MaterialTheme.typography.bodySmall)
            if (assignment.volume > 0) Text("Target: ${assignment.volume} ${assignment.satuan}", style = MaterialTheme.typography.bodySmall)
            if (assignment.demo) Text("DATA DEMONSTRASI", color = MaterialTheme.colorScheme.tertiary, fontWeight = FontWeight.Bold)
        }
    }
}

@Composable
fun AssignmentCreateScreen(
    role: String,
    onBack: () -> Unit,
    onCreated: (String) -> Unit,
    model: AssignmentOptionsViewModel = viewModel()
) {
    val state by model.state.collectAsState()
    var activity by remember { mutableStateOf<ActivitySummary?>(null) }
    var partner by remember { mutableStateOf<PartnerOption?>(null) }
    var showActivityPicker by rememberSaveable { mutableStateOf(false) }
    var showPartnerPicker by rememberSaveable { mutableStateOf(false) }
    var confirmSave by rememberSaveable { mutableStateOf(false) }
    var description by rememberSaveable { mutableStateOf("") }
    var volume by rememberSaveable { mutableStateOf("") }
    var unit by rememberSaveable { mutableStateOf("") }
    var territory by rememberSaveable { mutableStateOf("") }
    var startDate by rememberSaveable { mutableStateOf("") }
    var endDate by rememberSaveable { mutableStateOf("") }
    var contractCategory by rememberSaveable { mutableStateOf("PENDATAAN_LAPANGAN") }
    val targets = remember { mutableStateListOf(ActivityTarget()) }

    LaunchedEffect(Unit) { model.loadOptions() }
    LaunchedEffect(activity?.id) {
        activity?.let { selected ->
            description = selected.uraian
            volume = selected.targetVolume.takeIf { it > 0 }?.toString() ?: ""
            unit = selected.satuan
            territory = selected.wilayah
            startDate = selected.tglMulai
            endDate = selected.tglSelesai
            targets.clear()
            targets.addAll(selected.targetPekerjaan.ifEmpty {
                listOf(ActivityTarget(uraian = selected.uraian, target = selected.targetVolume, satuan = selected.satuan,
                    wilayah = selected.wilayah, jadwalMulai = selected.tglMulai, jadwalSelesai = selected.tglSelesai))
            })
        }
    }
    LaunchedEffect(state.saved?.id) { state.saved?.id?.let(onCreated) }

    val canCreate = role in setOf("ADMINISTRATOR_SISTEM", "PENGELOLA_KEGIATAN", "KOORDINATOR_LAPANGAN")
    Column(
        Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(9.dp)
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            TextButton(onClick = onBack) { Text("‹ Kembali") }
            Text("Buat Penugasan", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
        }
        if (state.loading && state.options.kegiatan.isEmpty()) {
            CircularProgressIndicator()
            Text("Memuat kegiatan dan mitra terverifikasi…")
        } else if (state.forbidden) {
            AssignmentMessage("Akses tidak diizinkan", state.error ?: "Peran ini tidak dapat membuat penugasan.")
        } else {
            state.error?.let { AssignmentMessage("Penugasan gagal disimpan", it) }
            TextButton(onClick = { showActivityPicker = true }, enabled = canCreate && !state.saving) {
                Text(activity?.let { "Kegiatan: ${it.kode} · ${it.nama}" } ?: "Pilih kegiatan aktif *")
            }
            TextButton(onClick = { showPartnerPicker = true }, enabled = canCreate && !state.saving) {
                Text(partner?.let { "Mitra: ${it.nama} · ${it.sobatId}" } ?: "Pilih mitra terverifikasi *")
            }
            Text("Jenis kontrak petugas *", fontWeight = FontWeight.Bold)
            Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
                assignmentCategories.forEach { (code, label) ->
                    FilterChip(
                        selected = contractCategory == code,
                        onClick = { contractCategory = code },
                        enabled = !state.saving,
                        label = { Text(label) }
                    )
                }
            }
            AssignmentInput("Uraian pekerjaan *", description, { description = it }, !state.saving)
            AssignmentInput("Target volume *", volume, { volume = it.filter { char -> char.isDigit() || char == '.' || char == ',' } }, !state.saving)
            AssignmentInput("Satuan *", unit, { unit = it }, !state.saving)
            AssignmentInput("Wilayah", territory, { territory = it }, !state.saving)
            AssignmentInput("Jadwal mulai (YYYY-MM-DD) *", startDate, { startDate = it }, !state.saving)
            AssignmentInput("Jadwal selesai (YYYY-MM-DD) *", endDate, { endDate = it }, !state.saving)
            Text("Target pekerjaan", fontWeight = FontWeight.Bold)
            targets.forEachIndexed { index, target ->
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        Text("Target ${index + 1}")
                        AssignmentInput("Uraian target *", target.uraian, { targets[index] = targets[index].copy(uraian = it) }, !state.saving)
                        AssignmentInput("Volume target *", target.target.takeIf { it > 0 }?.toString() ?: "", {
                            targets[index] = targets[index].copy(target = it.toDoubleOrNull() ?: 0.0)
                        }, !state.saving)
                        AssignmentInput("Satuan target", target.satuan, { targets[index] = targets[index].copy(satuan = it) }, !state.saving)
                        if (contractCategory == "PENDATAAN_DAN_PENGOLAHAN") {
                            Text("Kategori rincian target *", style = MaterialTheme.typography.labelMedium)
                            Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                                listOf("PENDATAAN_LAPANGAN" to "Pendataan", "PENGOLAHAN" to "Pengolahan").forEach { (code, label) ->
                                    FilterChip(
                                        selected = target.workCategory == code,
                                        onClick = { targets[index] = targets[index].copy(workCategory = code) },
                                        enabled = !state.saving,
                                        label = { Text(label) }
                                    )
                                }
                            }
                        }
                        AssignmentInput("Wilayah target", target.wilayah, { targets[index] = targets[index].copy(wilayah = it) }, !state.saving)
                        AssignmentInput("Mulai target", target.jadwalMulai, { targets[index] = targets[index].copy(jadwalMulai = it) }, !state.saving)
                        AssignmentInput("Selesai target", target.jadwalSelesai, { targets[index] = targets[index].copy(jadwalSelesai = it) }, !state.saving)
                        if (targets.size > 1) TextButton(onClick = { targets.removeAt(index) }) { Text("Hapus target") }
                    }
                }
            }
            TextButton(onClick = { targets.add(ActivityTarget()) }, enabled = !state.saving) { Text("Tambah target pekerjaan") }
            Button(
                onClick = { confirmSave = true },
                enabled = canCreate && activity != null && partner != null && description.isNotBlank() &&
                    volume.isNotBlank() && unit.isNotBlank() && startDate.isNotBlank() && endDate.isNotBlank() && !state.saving &&
                    (contractCategory != "PENDATAAN_DAN_PENGOLAHAN" ||
                        (targets.any { it.workCategory == "PENDATAAN_LAPANGAN" } && targets.any { it.workCategory == "PENGOLAHAN" })),
                modifier = Modifier.fillMaxWidth()
            ) { Text(if (state.saving) "Menyimpan…" else "Simpan penugasan") }
        }
    }

    if (showActivityPicker) {
        AlertDialog(
            onDismissRequest = { showActivityPicker = false },
            title = { Text("Pilih kegiatan aktif") },
            text = {
                Column(Modifier.height(360.dp).verticalScroll(rememberScrollState())) {
                    state.options.kegiatan.forEach { option ->
                        TextButton(onClick = { activity = option; showActivityPicker = false }) {
                            Text("${option.kode} · ${option.nama} · ${option.tahunAnggaran}")
                        }
                    }
                    if (state.options.kegiatan.isEmpty()) Text("Tidak ada kegiatan aktif dalam lingkup akun.")
                }
            },
            confirmButton = { TextButton(onClick = { showActivityPicker = false }) { Text("Tutup") } }
        )
    }
    if (showPartnerPicker) {
        AlertDialog(
            onDismissRequest = { showPartnerPicker = false },
            title = { Text("Pilih mitra terverifikasi") },
            text = {
                Column(Modifier.height(360.dp).verticalScroll(rememberScrollState())) {
                    state.options.mitra.forEach { option ->
                        TextButton(onClick = { partner = option; showPartnerPicker = false }) {
                            Text("${option.nama}${if (option.sobatId.isNotBlank()) " · ${option.sobatId}" else ""}")
                        }
                    }
                    if (state.options.mitra.isEmpty()) Text("Tidak ada mitra aktif dan terverifikasi.")
                }
            },
            confirmButton = { TextButton(onClick = { showPartnerPicker = false }) { Text("Tutup") } }
        )
    }
    if (confirmSave) {
        AlertDialog(
            onDismissRequest = { confirmSave = false },
            title = { Text("Simpan penugasan?") },
            text = { Text("Server akan memeriksa status mitra, kegiatan aktif, lingkup akses, dan konflik jadwal sebelum menyimpan.") },
            confirmButton = {
                Button(onClick = {
                    confirmSave = false
                    val selectedActivity = activity
                    val selectedPartner = partner
                    if (selectedActivity != null && selectedPartner != null) {
                        model.create(AssignmentWriteRequest(
                            kegiatanId = selectedActivity.id,
                            mitraId = selectedPartner.id,
                            contractCategory = contractCategory,
                            uraian = description.trim(),
                            targetPekerjaan = targets.map { target ->
                                target.copy(
                                    uraian = target.uraian.trim(),
                                    workCategory = if (contractCategory == "PENDATAAN_DAN_PENGOLAHAN") target.workCategory else contractCategory
                                )
                            },
                            volume = volume.replace(',', '.').toDoubleOrNull() ?: 0.0,
                            satuan = unit.trim(),
                            wilayah = territory.trim(),
                            jadwalMulai = startDate.trim(),
                            jadwalSelesai = endDate.trim()
                        ))
                    }
                }) { Text("Konfirmasi") }
            },
            dismissButton = { TextButton(onClick = { confirmSave = false }) { Text("Batal") } }
        )
    }
}

@Composable
private fun AssignmentInput(label: String, value: String, onValueChange: (String) -> Unit, enabled: Boolean) {
    OutlinedTextField(
        value = value,
        onValueChange = onValueChange,
        label = { Text(label) },
        enabled = enabled,
        singleLine = true,
        modifier = Modifier.fillMaxWidth()
    )
}

@Composable
private fun AssignmentMessage(title: String, body: String) {
    Card(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Text(title, fontWeight = FontWeight.Bold)
            Text(body)
        }
    }
}

private fun labelContractCategory(category: String): String = when (category) {
    "PENDATAAN_LAPANGAN" -> "Petugas Pendataan Lapangan (Pemetaan)"
    "PENGOLAHAN" -> "Petugas Pengolahan"
    "PENDATAAN_DAN_PENGOLAHAN" -> "Petugas Pendataan dan Pengolahan"
    else -> category
}
