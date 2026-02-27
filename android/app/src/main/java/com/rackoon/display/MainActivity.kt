package com.rackoon.display

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import kotlinx.coroutines.delay
import okhttp3.OkHttpClient
import okhttp3.logging.HttpLoggingInterceptor
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import retrofit2.http.GET
import retrofit2.http.Query

data class FeedItem(
    val id: Long,
    val display_message: String? = null,
    val created_at: String? = null,
)

data class FeedResponse(
    val items: List<FeedItem> = emptyList(),
)

private interface Api {
    @GET("api.php")
    suspend fun displayFeed(@Query("action") action: String = "display-feed"): FeedResponse
}

private data class DisplayState(
    val message: String,
    val hint: String,
    val color: Color,
)

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()

        val baseUrl = BuildConfig.API_BASE_URL
        val api = Retrofit.Builder()
            .baseUrl(if (baseUrl.endsWith('/')) baseUrl else "$baseUrl/")
            .client(
                OkHttpClient.Builder()
                    .addInterceptor(HttpLoggingInterceptor().apply { level = HttpLoggingInterceptor.Level.BASIC })
                    .build()
            )
            .addConverterFactory(GsonConverterFactory.create())
            .build()
            .create(Api::class.java)

        setContent {
            KioskScreen(api)
        }
    }

    override fun onResume() {
        super.onResume()
        // Attempts lock-task mode for managed/kiosk devices.
        runCatching { startLockTask() }
    }
}

@Composable
private fun KioskScreen(api: Api) {
    var state by remember {
        mutableStateOf(
            DisplayState(
                message = "Ootan andmeid...",
                hint = "Kontrollin feed endpointi",
                color = Color(0xFF1F2937),
            )
        )
    }

    LaunchedEffect(Unit) {
        while (true) {
            state = try {
                val latest = api.displayFeed().items.firstOrNull()?.display_message ?: "Ootan andmeid..."
                mapMessage(latest)
            } catch (_: Exception) {
                DisplayState(
                    message = "Ühenduse viga",
                    hint = "Kontrolli API aadressi ja võrku",
                    color = Color(0xFF111827),
                )
            }
            delay(2_000)
        }
    }

    Column(
        modifier = Modifier
            .fillMaxSize()
            .background(state.color)
            .padding(24.dp),
        verticalArrangement = Arrangement.Center,
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Text(
            text = state.message,
            color = Color.White,
            fontSize = 44.sp,
            fontWeight = FontWeight.Bold,
            textAlign = TextAlign.Center,
            lineHeight = 52.sp,
        )
        Text(
            text = state.hint,
            color = Color(0xFFE5E7EB),
            fontSize = 20.sp,
            textAlign = TextAlign.Center,
            modifier = Modifier.padding(top = 20.dp),
        )
    }
}

private fun mapMessage(message: String): DisplayState {
    return when (message.trim()) {
        "Suunata Service Lobby alale" -> DisplayState(
            message = message,
            hint = "Suuna klient Service Lobby alale",
            color = Color(0xFFB91C1C),
        )

        "Suunata parklasse" -> DisplayState(
            message = message,
            hint = "Suuna klient parklasse",
            color = Color(0xFF166534),
        )

        "Sisenemine keelatud" -> DisplayState(
            message = message,
            hint = "Palun võtke ühendust administraatoriga",
            color = Color(0xFF374151),
        )

        else -> DisplayState(
            message = message,
            hint = "Uuendan infot iga 2 sekundi järel",
            color = Color(0xFF1F2937),
        )
    }
}
