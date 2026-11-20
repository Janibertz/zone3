package run.zone3.app;

import android.os.Bundle;
import androidx.core.splashscreen.SplashScreen;
import com.getcapacitor.BridgeActivity;

public class MainActivity extends BridgeActivity {

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        // Vor super.onCreate(): übergibt vom Startbild an das App-Theme.
        // Ohne den Aufruf blieb auf Android 12+ das Startbild mit dem
        // Standard-Roboter stehen.
        SplashScreen.installSplashScreen(this);
        super.onCreate(savedInstanceState);
    }
}
