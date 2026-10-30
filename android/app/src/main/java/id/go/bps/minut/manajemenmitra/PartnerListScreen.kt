package id.go.bps.minut.manajemenmitra

import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
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
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.lifecycle.viewmodel.compose.viewModel
import id.go.bps.minut.manajemenmitra.data.PartnerSummary

private val statusFilters = listOf(
    "" to "Semua",
    "DRAFT" to "Draft",
    "MENUNGGU_VERIFIKASI" to "Menunggu verifikasi",
    "PERLU_PERBAIKAN" to "Perlu perbaikan",
    "TERVERIFIKASI" to "Terverifikasi",
    "TIDAK_MEMENUHI" to "Tidak memenuhi"
)

@Composable
fun PartnerListScreen(
    onBack: () -> Unit,
    onOpenPartner: (String) -> Unit,
    onCreatePartner: () -> Unit,
    canManage: Boolean,
    model: PartnerListViewModel = viewModel()
) {
    val state by model.state.collectAsState()
    var query by rememberSaveable { mutableStateOf("") }
    var selectedStatus by rememberSaveable { mutableStateOf("") }

    LaunchedEffect(Unit) { model.load() }

    Column(
        modifier = Modifier.fillMaxSize().padding(horizontal = 16.dp, vertical = 10.dp),
        verticalArrangement = Arrangement.spacedBy(8.dp)
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            TextButton(onClick = onBack) { Text("‹ Kembali") }
            Spacer(Modifier.width(4.dp))
            Text("Data Mitra", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
        }
        if (canManage) {
            Button(onClick = onCreatePartner, modifier = Modifier.fillMaxWidth()) {
                Text("Tambah mitra")
            }
        }

        OutlinedTextField(
            value = query,
            onValueChange = { query = it },
            label = { Text("Cari nama atau ID mitra") },
            singleLine = true,
            enabled = !state.loading,
            modifier = Modifier.fillMaxWidth()
        )
        Button(
            onClick = { model.load(query = query, status = selectedStatus) },
            enabled = !state.loading,
            modifier = Modifier.fillMaxWidth()
        ) {
            Text("Cari mitra")
        }

        Row(
            modifier = Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()),
            horizontalArrangement = Arrangement.spacedBy(8.dp)
        ) {
            statusFilters.forEach { (value, label) ->
                FilterChip(
                    selected = selectedStatus == value,
                    onClick = {
                        selectedStatus = value
                        model.load(query = query, status = value)
                    },
                    label = { Text(label) },
                    enabled = !state.loading
                )
            }
        }

        if (state.loading && state.items.isEmpty()) {
            Column(
                modifier = Modifier.fillMaxSize(),
                horizontalAlignment = Alignment.CenterHorizontally,
                verticalArrangement = Arrangement.Center
            ) {
                CircularProgressIndicator()
                Spacer(Modifier.height(12.dp))
                Text("Memuat data mitra…")
            }
        } else if (state.forbidden) {
            MessageCard("Akses tidak diizinkan", "Peran akun ini tidak memiliki izin untuk melihat daftar mitra.")
        } else if (state.error != null) {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                MessageCard("Data gagal dimuat", state.error ?: "Terjadi kesalahan.")
                Button(onClick = { model.load(query = query, status = selectedStatus) }) {
                    Text("Coba lagi")
                }
            }
        } else if (state.loaded && state.items.isEmpty()) {
            MessageCard("Mitra tidak ditemukan", "Ubah kata pencarian atau filter status, lalu coba lagi.")
        } else {
            if (state.loading) Text("Memperbarui daftar…", style = MaterialTheme.typography.labelMedium)
            Text("${state.items.size} dari ${state.total} mitra", style = MaterialTheme.typography.labelMedium)
            LazyColumn(
                modifier = Modifier.weight(1f),
                verticalArrangement = Arrangement.spacedBy(8.dp)
            ) {
                items(state.items, key = { it.id }) { partner ->
                    PartnerCard(partner, onClick = { onOpenPartner(partner.id) })
                }
                if (state.items.size < state.total) {
                    item {
                        Button(
                            onClick = model::loadMore,
                            enabled = !state.loading,
                            modifier = Modifier.fillMaxWidth()
                        ) {
                            Text(if (state.loading) "Memuat…" else "Muat berikutnya")
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun PartnerCard(partner: PartnerSummary, onClick: () -> Unit) {
    Card(
        modifier = Modifier.fillMaxWidth().clickable(onClick = onClick),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface)
    ) {
        Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Text(partner.nama.ifBlank { "Nama belum tersedia" }, fontWeight = FontWeight.Bold)
            if (partner.sobatId.isNotBlank()) {
                Text("ID mitra: ${partner.sobatId}", style = MaterialTheme.typography.bodySmall)
            }
            val area = listOf(partner.desa, partner.kecamatan).filter(String::isNotBlank).joinToString(", ")
            if (area.isNotBlank()) Text(area, style = MaterialTheme.typography.bodySmall)
            Text(
                "${labelStatus(partner.statusVerifikasi)} · ${if (partner.aktif) "Aktif" else "Tidak aktif"}",
                color = MaterialTheme.colorScheme.primary,
                style = MaterialTheme.typography.labelMedium
            )
            if (partner.demo) {
                Text("DATA DEMONSTRASI", color = MaterialTheme.colorScheme.tertiary, fontWeight = FontWeight.Bold)
            }
        }
    }
}

@Composable
private fun MessageCard(title: String, message: String) {
    Card(modifier = Modifier.fillMaxWidth()) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            Text(title, fontWeight = FontWeight.Bold)
            Text(message, style = MaterialTheme.typography.bodyMedium)
        }
    }
}

private fun labelStatus(status: String): String = when (status.uppercase()) {
    "DRAFT" -> "Draft"
    "MENUNGGU_VERIFIKASI" -> "Menunggu verifikasi"
    "PERLU_PERBAIKAN" -> "Perlu perbaikan"
    "TERVERIFIKASI" -> "Terverifikasi"
    "TIDAK_MEMENUHI" -> "Tidak memenuhi persyaratan"
    else -> "Status belum ditentukan"
}
