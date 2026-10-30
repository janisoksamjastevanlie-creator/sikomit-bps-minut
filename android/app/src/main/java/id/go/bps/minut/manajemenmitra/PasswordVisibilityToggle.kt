package id.go.bps.minut.manajemenmitra

import androidx.compose.foundation.Canvas
import androidx.compose.foundation.layout.size
import androidx.compose.material3.IconButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp

@Composable
fun PasswordVisibilityToggle(visible: Boolean, onToggle: () -> Unit, enabled: Boolean = true) {
    IconButton(
        onClick = onToggle,
        enabled = enabled,
        modifier = Modifier.semantics {
            contentDescription = if (visible) "Sembunyikan password" else "Tampilkan password"
        }
    ) {
        Canvas(Modifier.size(22.dp)) {
            val tint = Color(0xFF526A7D)
            val eye = Path().apply {
                moveTo(size.width * .08f, size.height * .5f)
                cubicTo(size.width * .30f, size.height * .12f, size.width * .70f, size.height * .12f, size.width * .92f, size.height * .5f)
                cubicTo(size.width * .70f, size.height * .88f, size.width * .30f, size.height * .88f, size.width * .08f, size.height * .5f)
            }
            drawPath(eye, tint, style = Stroke(width = 1.7.dp.toPx(), cap = StrokeCap.Round))
            drawCircle(tint, radius = size.minDimension * .12f, center = Offset(size.width * .5f, size.height * .5f))
            if (visible) {
                drawLine(
                    tint,
                    start = Offset(size.width * .15f, size.height * .15f),
                    end = Offset(size.width * .85f, size.height * .85f),
                    strokeWidth = 1.8.dp.toPx(),
                    cap = StrokeCap.Round
                )
            }
        }
    }
}
