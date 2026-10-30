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
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.lifecycle.viewmodel.compose.viewModel
import id.go.bps.minut.manajemenmitra.data.WorkReportSummary

private val ReportNavy = Color(0xFF063651)
private val ReportBlue = Color(0xFF0093DD)

@Composable
fun WorkReportScreen(
    role: String,
    onBack: () -> Unit,
    model: WorkReportViewModel = viewModel()
) {
    val state by model.state.collectAsState()
    val canReview = role in setOf("ADMIN_BPS", "PJK", "PEMERIKSA")
    var reviewItem by remember { mutableStateOf<WorkReportSummary?>(null) }

    androidx.compose.runtime.LaunchedEffect(Unit) { model.load() }

    Column(Modifier.fillMaxSize().padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
            TextButton(onClick = onBack) { Text("← Kembali") }
            TextButton(onClick = model::load, enabled = !state.loading && !state.saving) { Text("Muat ulang") }
        }
        Text("Hasil Pekerjaan", color = ReportNavy, fontWeight = FontWeight.Bold, style = androidx.compose.material3.MaterialTheme.typography.headlineSmall)
        Text("Laporan dibatasi sesuai akun dan penugasan. Bukti lampiran harus diperiksa manual sebelum hasil diterima.", color = Color(0xFF496578))

        if (state.loading && !state.loaded) {
            Column(Modifier.fillMaxWidth().weight(1f), verticalArrangement = Arrangement.Center) { CircularProgressIndicator() }
        } else if (state.forbidden) {
            ReportNotice("Akses tidak diizinkan", state.error ?: "Peran ini tidak memiliki akses.")
        } else if (state.error != null) {
            ReportNotice("Laporan gagal dimuat", state.error ?: "Terjadi kesalahan.")
        } else if (state.loaded && state.items.isEmpty()) {
            ReportNotice("Belum ada laporan", "Laporan hanya muncul setelah dikirim oleh petugas mitra.")
        } else {
            state.message?.let { ReportNotice("Tersimpan", it) }
            LazyColumn(
                modifier = Modifier.weight(1f),
                verticalArrangement = Arrangement.spacedBy(10.dp)
            ) {
                items(state.items, key = { it.id }) { report ->
                    WorkReportCard(report, canReview && report.status == "DIAJUKAN", state.saving) { reviewItem = report }
                }
            }
        }
    }

    reviewItem?.let { report ->
        var note by rememberSaveable(report.id) { mutableStateOf("") }
        AlertDialog(
            onDismissRequest = { if (!state.saving) reviewItem = null },
            title = { Text("Periksa hasil pekerjaan") },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text("${report.partnerName} · ${report.activityName}")
                    Text(report.summary)
                    report.items.forEach { item ->
                        Text("${item.description}: ${item.actualVolume} ${item.unit} (target ${item.target} ${item.unit})")
                    }
                    if (report.evidence.isNotEmpty()) {
                        Text("Lampiran: ${report.evidence.joinToString { "${it.originalFilename} (${it.scanStatus})" }}")
                        Text("Periksa file privat dari web sebelum menerima laporan.", color = Color(0xFF8A5700))
                    }
                    OutlinedTextField(value = note, onValueChange = { note = it }, label = { Text("Catatan pemeriksaan") }, modifier = Modifier.fillMaxWidth())
                    state.error?.let { Text(it, color = androidx.compose.material3.MaterialTheme.colorScheme.error) }
                }
            },
            confirmButton = {
                Row(horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                    TextButton(enabled = !state.saving && (report.evidence.isEmpty() || report.evidence.all { it.scanStatus == "CLEAN_MANUALLY_REVIEWED" }), onClick = {
                        model.review(report.id, "DITERIMA", note.takeIf(String::isNotBlank)); reviewItem = null
                    }) { Text("Terima") }
                    TextButton(enabled = !state.saving && note.isNotBlank(), onClick = {
                        model.review(report.id, "PERLU_PERBAIKAN", note); reviewItem = null
                    }) { Text("Perbaikan") }
                }
            },
            dismissButton = {
                TextButton(enabled = !state.saving && note.isNotBlank(), onClick = {
                    model.review(report.id, "DITOLAK", note); reviewItem = null
                }) { Text("Tolak") }
            }
        )
    }
}

@Composable
private fun WorkReportCard(report: WorkReportSummary, canReview: Boolean, saving: Boolean, onReview: () -> Unit) {
    Card(Modifier.fillMaxWidth(), colors = CardDefaults.cardColors(containerColor = Color.White)) {
        Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(5.dp)) {
            Text(report.partnerName.ifBlank { "Petugas mitra" }, color = ReportNavy, fontWeight = FontWeight.Bold)
            Text(report.activityName, color = Color(0xFF496578))
            Text(labelWorkReportStatus(report.status), color = ReportBlue, fontWeight = FontWeight.SemiBold)
            if (report.demo) Text("DATA DEMO — simulasi, bukan hasil operasional", color = Color(0xFF9A5A00), fontWeight = FontWeight.Bold)
            Text(report.summary, color = Color(0xFF304759))
            report.items.forEach { item ->
                Text("• ${item.description}: ${item.actualVolume} ${item.unit} / target ${item.target} ${item.unit}", color = Color(0xFF496578))
            }
            if (report.evidence.isNotEmpty()) Text("${report.evidence.size} lampiran privat · status pemeriksaan manual", color = Color(0xFF8A5700))
            if (canReview) {
                Spacer(Modifier.height(2.dp))
                Button(onClick = onReview, enabled = !saving, modifier = Modifier.fillMaxWidth()) { Text("Periksa laporan") }
            }
        }
    }
}

@Composable
private fun ReportNotice(title: String, body: String) {
    Card(Modifier.fillMaxWidth(), colors = CardDefaults.cardColors(containerColor = Color(0xFFEAF5FB))) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(5.dp)) {
            Text(title, color = ReportNavy, fontWeight = FontWeight.Bold)
            Text(body, color = Color(0xFF496578))
        }
    }
}
