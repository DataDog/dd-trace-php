FROM mcr.microsoft.com/windows/servercore:1809

# https://aka.ms/vs/14/release/vs_buildtools.exe has been removed
ADD vs14_buildtools.exe /tmp/vs_buildtools.exe
RUN /tmp/vs_buildtools.exe --quiet --wait --add Microsoft.VisualStudio.Workload.VCTools --add Microsoft.Net.Component.4.7.SDK --add Microsoft.VisualStudio.Component.VC.Tools.x86.x64 --add Microsoft.VisualStudio.Component.Windows10SDK.17763
