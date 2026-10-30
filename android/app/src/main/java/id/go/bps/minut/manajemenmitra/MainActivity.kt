package id.go.bps.minut.manajemenmitra

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.viewmodel.compose.viewModel
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.rememberNavController
import androidx.navigation.NavType
import androidx.navigation.navArgument
import id.go.bps.minut.manajemenmitra.data.DashboardSummary

private val BpsNavy = Color(0xFF063651)
private val BpsBlue = Color(0xFF0093DD)
private val BpsGreen = Color(0xFF68B92E)
private val BpsBackground = Color(0xFFF1F6FA)

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContent {
            MaterialTheme(colorScheme = lightColorScheme(
                primary = BpsNavy,
                secondary = BpsBlue,
                tertiary = BpsGreen,
                background = BpsBackground,
                surface = Color.White
            )) {
                val session: SessionViewModel = viewModel()
                val state by session.state.collectAsState()
                val user = state.user
                Surface(modifier = Modifier.fillMaxSize(), color = BpsBackground) {
                    if (user == null) {
                        LoginScreen(state.loading, state.error, session::login)
                    } else if (user.mustChangePassword) {
                        PasswordChangeScreen(state.loading, state.error, session::changePassword)
                    } else {
                        val navController = rememberNavController()
                        NavHost(navController = navController, startDestination = "dashboard") {
                            composable("dashboard") {
                                AdministrativeDashboard(
                                    userName = user.nama,
                                    role = user.role,
                                    summary = state.dashboard,
                                    loading = state.loading,
                                    error = state.error,
                                    onRefresh = session::refresh,
                                    onLogout = session::logout,
                                    onOpenPartners = { navController.navigate("mitra") },
                                    onOpenActivities = { navController.navigate("kegiatan") },
                                    onOpenAssignments = { navController.navigate("penugasan") },
                                    onOpenWorkReports = { navController.navigate("hasil-pekerjaan") }
                                )
                            }
                            composable("mitra") {
                                PartnerListScreen(
                                    onBack = { navController.popBackStack() },
                                    onOpenPartner = { id -> navController.navigate("mitra/detail/$id") },
                                    onCreatePartner = { navController.navigate("mitra/new") },
                                    canManage = user.role == "ADMINISTRATOR_SISTEM" || user.role == "ADMIN_MITRA"
                                )
                            }
                            composable("mitra/new") {
                                PartnerProfileScreen(
                                    partnerId = null,
                                    userRole = user.role,
                                    onBack = { navController.popBackStack() },
                                    onCreated = { id ->
                                        navController.navigate("mitra/detail/$id") {
                                            popUpTo("mitra/new") { inclusive = true }
                                        }
                                    }
                                )
                            }
                            composable(
                                route = "mitra/detail/{id}",
                                arguments = listOf(navArgument("id") { type = NavType.StringType })
                            ) { entry ->
                                PartnerProfileScreen(
                                    partnerId = entry.arguments?.getString("id"),
                                    userRole = user.role,
                                    onBack = { navController.popBackStack() },
                                    onCreated = {}
                                )
                            }
                            composable("kegiatan") {
                                ActivityListScreen(
                                    role = user.role,
                                    onBack = { navController.popBackStack() },
                                    onOpenActivity = { id -> navController.navigate("kegiatan/detail/$id") },
                                    onCreateActivity = { navController.navigate("kegiatan/new") }
                                )
                            }
                            composable("kegiatan/new") {
                                ActivityProfileScreen(
                                    activityId = null,
                                    role = user.role,
                                    onBack = { navController.popBackStack() },
                                    onCreated = { id ->
                                        navController.navigate("kegiatan/detail/$id") {
                                            popUpTo("kegiatan/new") { inclusive = true }
                                        }
                                    }
                                )
                            }
                            composable(
                                route = "kegiatan/detail/{id}",
                                arguments = listOf(navArgument("id") { type = NavType.StringType })
                            ) { entry ->
                                ActivityProfileScreen(
                                    activityId = entry.arguments?.getString("id"),
                                    role = user.role,
                                    onBack = { navController.popBackStack() },
                                    onCreated = {}
                                )
                            }
                            composable("penugasan") {
                                AssignmentListScreen(
                                    role = user.role,
                                    onBack = { navController.popBackStack() },
                                    onCreate = { navController.navigate("penugasan/new") }
                                )
                            }
                            composable("penugasan/new") {
                                AssignmentCreateScreen(
                                    role = user.role,
                                    onBack = { navController.popBackStack() },
                                    onCreated = { navController.popBackStack() }
                                )
                            }
                            composable("hasil-pekerjaan") {
                                WorkReportScreen(
                                    role = user.role,
                                    onBack = { navController.popBackStack() }
                                )
                            }
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun LoginScreen(loading: Boolean, error: String?, onLogin: (String, String) -> Unit) {
    var username by rememberSaveable { mutableStateOf("") }
    var password by rememberSaveable { mutableStateOf("") }
    var showPassword by rememberSaveable { mutableStateOf(false) }
    Column(
        modifier = Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(24.dp),
        verticalArrangement = Arrangement.Center
    ) {
        Text("BPS KABUPATEN MINAHASA UTARA", color = BpsBlue, fontSize = 11.sp, fontWeight = FontWeight.Bold)
        Text("Manajemen Mitra", color = BpsNavy, fontSize = 28.sp, fontWeight = FontWeight.Bold)
        Text("Administrasi SPK dan BAST", color = Color(0xFF496578))
        Spacer(Modifier.height(24.dp))
        OutlinedTextField(
            value = username,
            onValueChange = { username = it },
            label = { Text("Nama pengguna") },
            singleLine = true,
            enabled = !loading,
            modifier = Modifier.fillMaxWidth()
        )
        Spacer(Modifier.height(10.dp))
        OutlinedTextField(
            value = password,
            onValueChange = { password = it },
            label = { Text("Kata sandi") },
            singleLine = true,
            enabled = !loading,
            visualTransformation = if (showPassword) VisualTransformation.None else PasswordVisualTransformation(),
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password),
            trailingIcon = { PasswordVisibilityToggle(showPassword, { showPassword = !showPassword }, !loading) },
            modifier = Modifier.fillMaxWidth()
        )
        if (error != null) {
            Spacer(Modifier.height(10.dp))
            Text(error, color = MaterialTheme.colorScheme.error)
        }
        Spacer(Modifier.height(16.dp))
        Button(
            onClick = { onLogin(username, password) },
            enabled = !loading && username.isNotBlank() && password.isNotBlank(),
            modifier = Modifier.fillMaxWidth()
        ) {
            if (loading) CircularProgressIndicator(strokeWidth = 2.dp, modifier = Modifier.size(18.dp))
            else Text("Masuk")
        }
        Spacer(Modifier.height(12.dp))
        Text("Gunakan akun yang dikelola administrator instansi.", color = Color(0xFF65798A), fontSize = 12.sp)
    }
}

@Composable
private fun AdministrativeDashboard(
    userName: String,
    role: String,
    summary: DashboardSummary?,
    loading: Boolean,
    error: String?,
    onRefresh: () -> Unit,
    onLogout: () -> Unit,
    onOpenPartners: () -> Unit,
    onOpenActivities: () -> Unit,
    onOpenAssignments: () -> Unit,
    onOpenWorkReports: () -> Unit
) {
    Column(Modifier.fillMaxSize()) {
        Card(
            modifier = Modifier.fillMaxWidth().padding(14.dp),
            colors = CardDefaults.cardColors(containerColor = BpsNavy)
        ) {
            Column(Modifier.padding(18.dp)) {
                Text("BPS KABUPATEN MINAHASA UTARA", color = Color(0xFFBDE9FA), fontSize = 10.sp, fontWeight = FontWeight.Bold)
                Text("Dashboard Administrasi", color = Color.White, fontSize = 21.sp, fontWeight = FontWeight.Bold)
                Text("$userName · ${role.replace('_', ' ').lowercase().replaceFirstChar { it.uppercase() }}", color = Color.White, fontSize = 12.sp)
            }
        }
        Row(
            modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp),
            horizontalArrangement = Arrangement.spacedBy(8.dp),
            verticalAlignment = Alignment.CenterVertically
        ) {
            Button(onClick = onRefresh, enabled = !loading, modifier = Modifier.weight(1f)) {
                if (loading) CircularProgressIndicator(strokeWidth = 2.dp, modifier = Modifier.size(18.dp)) else Text("Muat ulang")
            }
            TextButton(onClick = onLogout, enabled = !loading) { Text("Keluar") }
        }
        if (role == "ADMINISTRATOR_SISTEM" || role == "ADMIN_MITRA" || role == "MITRA" || role == "PEMERIKSA_ADMINISTRASI") {
            TextButton(onClick = onOpenPartners, modifier = Modifier.padding(horizontal = 12.dp)) {
                Text(when (role) {
                    "MITRA" -> "Profil mitra saya"
                    "PEMERIKSA_ADMINISTRASI" -> "Periksa mitra"
                    else -> "Kelola data mitra"
                })
            }
        }
        if (role in setOf("ADMINISTRATOR_SISTEM", "PENGELOLA_KEGIATAN", "KOORDINATOR_LAPANGAN", "MITRA")) {
            TextButton(onClick = onOpenActivities, modifier = Modifier.padding(horizontal = 12.dp)) {
                Text(if (role == "MITRA") "Kegiatan penugasan saya" else "Kegiatan")
            }
        }
        if (role in setOf("ADMIN_BPS", "PJK", "OPERATOR", "PEMERIKSA", "PETUGAS_MITRA")) {
            TextButton(onClick = onOpenWorkReports, modifier = Modifier.padding(horizontal = 12.dp)) {
                Text(if (role == "PETUGAS_MITRA") "Laporan saya" else "Hasil pekerjaan")
            }
        }
        if (role in setOf("ADMINISTRATOR_SISTEM", "PENGELOLA_KEGIATAN", "KOORDINATOR_LAPANGAN", "MITRA")) {
            TextButton(onClick = onOpenAssignments, modifier = Modifier.padding(horizontal = 12.dp)) {
                Text(if (role == "MITRA") "Penugasan saya" else "Penugasan")
            }
        }
        if (error != null) Text(error, color = MaterialTheme.colorScheme.error, modifier = Modifier.padding(horizontal = 18.dp, vertical = 6.dp))

        if (summary == null) {
            Column(Modifier.weight(1f).fillMaxWidth().padding(24.dp), verticalArrangement = Arrangement.Center, horizontalAlignment = Alignment.CenterHorizontally) {
                if (loading) CircularProgressIndicator() else Text("Ringkasan belum tersedia. Muat ulang untuk mencoba lagi.")
            }
        } else {
            LazyColumn(
                modifier = Modifier.weight(1f).fillMaxWidth(),
                contentPadding = androidx.compose.foundation.layout.PaddingValues(14.dp),
                verticalArrangement = Arrangement.spacedBy(10.dp)
            ) {
                if (summary.demoMode) item {
                    InfoCard("MODE DEMONSTRASI", "Data bertanda demo dikecualikan dari angka operasional.", Color(0xFFFFF4E5))
                }
                if (summary.mitra.total + summary.kegiatan.total + summary.penugasan.total + summary.spk.final + summary.bast.final == 0) item {
                    InfoCard("Belum ada data operasional", "Dashboard hanya menampilkan ringkasan dari backend; tidak ada angka contoh yang dibuat di aplikasi.", Color(0xFFEAF5FB))
                }
                item { CountCard("Mitra", "${summary.mitra.aktif} aktif · ${summary.mitra.total} total", "${summary.mitra.terverifikasi} terverifikasi · ${summary.mitra.menungguVerifikasi + summary.mitra.perluPerbaikan} perlu tindak lanjut") }
                item { CountCard("Kegiatan", "${summary.kegiatan.aktif} aktif · ${summary.kegiatan.total} total", "Kegiatan statistik terdaftar") }
                item { CountCard("Penugasan", "${summary.penugasan.aktif} aktif · ${summary.penugasan.total} total", "${summary.penugasan.dalamPelaksanaan} dalam pelaksanaan") }
                item { DocumentCard("SPK", summary.spk) }
                item { CountCard("Pekerjaan", "${summary.pekerjaan.dalamPelaksanaan} dalam pelaksanaan", "${summary.pekerjaan.menungguPemeriksaan} menunggu pemeriksaan · ${summary.pekerjaan.perluPerbaikan} perlu perbaikan · ${summary.pekerjaan.terverifikasi} terverifikasi") }
                item { DocumentCard("BAST", summary.bast) }
                item { CountCard("Arsip final", "${summary.arsip.total} dokumen", "SPK dan BAST final") }
                item {
                    Text("Batas proses aplikasi: BAST final diarsipkan. Tidak ada fitur transaksi keuangan.", color = Color(0xFF496578), fontSize = 12.sp, modifier = Modifier.padding(vertical = 8.dp))
                }
            }
        }
    }
}

@Composable
private fun DocumentCard(title: String, counts: id.go.bps.minut.manajemenmitra.data.DocumentCounts) {
    CountCard(
        title,
        "${counts.final} final",
        "${counts.draft} draft · ${counts.menungguPemeriksaan} pemeriksaan · ${counts.menungguPersetujuan} persetujuan · ${counts.menungguPenandatanganan} tanda tangan"
    )
}

@Composable
private fun CountCard(title: String, primary: String, detail: String) {
    Card(modifier = Modifier.fillMaxWidth(), colors = CardDefaults.cardColors(containerColor = Color.White)) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(5.dp)) {
            Text(title, color = BpsNavy, fontWeight = FontWeight.Bold)
            Text(primary, color = BpsBlue, fontSize = 19.sp, fontWeight = FontWeight.Bold)
            Text(detail, color = Color(0xFF65798A), fontSize = 12.sp)
        }
    }
}

@Composable
private fun InfoCard(title: String, message: String, color: Color) {
    Card(modifier = Modifier.fillMaxWidth(), colors = CardDefaults.cardColors(containerColor = color)) {
        Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Text(title, color = BpsNavy, fontWeight = FontWeight.Bold)
            Text(message, color = Color(0xFF496578), fontSize = 12.sp)
        }
    }
}
